/** The approvals a paused run asks for, and the resume an answer starts. */

import { describe, expect, it } from 'vitest'
import { ChatRequestError } from '../src/errors'
import type { AgUiEvent, ChatConnectionRequest } from '../src/types'
import {
  transport,
  promptIn,
  deferred,
  payloadMessage,
  pausedPayloadMessage,
  saveNoteInterrupt,
  sourceRef,
  chatWith,
  settle,
  pauses,
  resumes,
  threadServer,
  transcriptOf,
  settleAttach,
} from './chatHarness'

describe('approvals', () => {
  it('offers the approvals a paused run reported', async () => {
    const { connection, next } = transport()

    next(pauses)

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')

    expect(chat.pendingApprovals).toEqual([expect.objectContaining({ id: 'call-1', tool: 'SaveNote' })])
  })

  /*
   * The regression that made `pendingApprovals` scan instead of reading the
   * tail. A message typed while a run streams is rendered at once and drains
   * the moment the run ends — so a run that ends *by pausing* leaves the
   * paused message second from last, and reading only the tail lost it.
   */
  it('still finds the pause when a queued turn was drained behind it', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit) => {
      await held.promise

      pauses(emit, {} as ChatConnectionRequest)
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Save a note')

    await settle()
    void chat.send('And another thing')

    held.resolve()
    await running

    const statuses = chat.messages.map((message) => message.status)

    expect(statuses.indexOf('awaiting_approval')).toBeLessThan(statuses.length - 1)
    expect(chat.pendingApprovals).toHaveLength(1)
  })

  it('marks the paused message answered and sends only decisions', async () => {
    const { connection, next, requests } = transport()

    next(pauses)

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    expect(requests[1].body).toMatchObject({
      threadId: 'conv-1',
      resume: [
        {
          interruptId: 'call-1',
          status: 'resolved',
          payload: { approved: true },
        },
      ],
    })
    expect(promptIn(requests[1])).toBeUndefined()

    const paused = chat.messages.find((message) => message.id === chat.messages[1].id)

    expect(paused?.status).toBe('approval_responded')
    expect(chat.pendingApprovals).toEqual([])
  })

  /*
   * The resumed run replays the call it was paused on, and the paused message is
   * still on screen showing that same call. Folding the result back into the
   * message that asked is what keeps one approval from rendering as two cards:
   * the first shipped exactly that, an "awaiting approval" bubble stacked on an
   * identical "complete" one.
   */
  it('resolves an approved call on the message that asked, not on the resumed one', async () => {
    const { connection, next } = transport()

    next(pauses)
    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-2' })
      emit({
        type: 'TOOL_CALL_RESULT',
        messageId: 'm-2',
        toolCallId: 'call-1',
        content: 'Saved notes.txt.',
      })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-2',
        delta: 'Saved!',
      })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-2' })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    const assistants = chat.messages.filter((message) => message.role === 'assistant')

    expect(assistants.flatMap((message) => message.toolCalls).map((call) => call.id)).toEqual(['call-1'])

    expect(assistants[0].toolCalls[0]).toMatchObject({
      id: 'call-1',
      name: 'SaveNote',
      result: 'Saved notes.txt.',
      state: 'complete',
    })

    // Rendered from the parts, so the pill has to have moved too.
    expect(assistants[0].parts).toContainEqual(
      expect.objectContaining({
        type: 'dynamic-tool',
        toolCallId: 'call-1',
        state: 'complete',
      }),
    )

    expect(assistants[1].content).toBe('Saved!')
    expect(assistants[1].parts.filter((part) => part.type === 'dynamic-tool')).toEqual([])
  })

  /*
   * The resumed run updates the paused row in place and reports it as the answer's id. A page loaded during the
   * pause already renders that row, so the answer that adopted its id used to retire its own turn and vanish
   * behind the stored, paused copy until a reload.
   */
  it('shows the answer on the paused message a page was loaded with, once the resumed run stores it there', async () => {
    const { connection, next } = transport()

    next(resumes({ messageId: 'stored-1' }))

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        messages: [
          payloadMessage({ id: 'stored-user', role: 'user', content: 'Save it.' }),
          { ...pausedPayloadMessage(), content: '' },
        ],
        interrupts: [saveNoteInterrupt],
      }),
      { connection },
    )

    await chat.approve('call-1')

    const assistants = chat.messages.filter((message) => message.role === 'assistant')

    expect(assistants).toHaveLength(1)
    expect(assistants[0]).toMatchObject({ id: 'stored-1', content: 'Saved!', status: 'complete' })
    expect(assistants[0].toolCalls).toEqual([
      expect.objectContaining({ id: 'call-1', result: 'Saved notes.txt.', state: 'complete' }),
    ])
    expect(assistants[0].parts.map((part) => part.type)).toEqual(['dynamic-tool', 'text'])
  })

  it('lets the transcript’s updated copy of the paused message replace the answer it was shown with', async () => {
    const { connection, next } = transport()

    next(resumes({ messageId: 'stored-1' }))

    const source = sourceRef({
      threadId: 'conv-1',
      messages: [{ ...pausedPayloadMessage(), content: '' }],
      interrupts: [saveNoteInterrupt],
    })
    const chat = chatWith(source, { connection })

    await chat.approve('call-1')

    source.current = {
      ...source.current,
      messages: [payloadMessage({ id: 'stored-1', content: 'Saved!' })],
      interrupts: [],
    }

    expect(transcriptOf(chat.messages)).toEqual([['assistant', 'Saved!']])
  })

  it('keeps the answer apart from a pause this page streamed itself', async () => {
    const { connection, next } = transport()

    next(pauses)
    next(resumes({ messageId: 'stored-1' }))

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    const assistants = chat.messages.filter((message) => message.role === 'assistant')

    expect(assistants.map((message) => message.toolCalls.map((call) => call.state))).toEqual([['complete'], []])
    expect(assistants[1]).toMatchObject({ id: 'stored-1', content: 'Saved!' })
  })

  it('shows the answer on a pause this page streamed, once the pause and the answer name the same stored row', async () => {
    const { connection, next } = transport()

    next((emit, request) =>
      pauses(
        (event) =>
          emit(event.type === 'RUN_FINISHED' ? { ...event, messageId: 'stored-1', userMessageId: 'user-1' } : event),
        request,
      ),
    )
    next(resumes({ messageId: 'stored-1' }))

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    const assistants = chat.messages.filter((message) => message.role === 'assistant')

    expect(chat.messages.map((message) => message.id)).toEqual(['user-1', 'stored-1'])
    expect(assistants[0]).toMatchObject({ id: 'stored-1', content: 'Saved!', status: 'complete' })
    expect(assistants[0].toolCalls).toEqual([
      expect.objectContaining({ id: 'call-1', result: 'Saved notes.txt.', state: 'complete' }),
    ])
  })

  it('keeps one message for a stored row the agent paused on twice', async () => {
    const { connection, next } = transport()

    const pausesOn = (runId: string, toolCallId: string, resolving?: string) => (emit: (event: AgUiEvent) => void) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId })

      if (resolving !== undefined) {
        emit({ type: 'TOOL_CALL_RESULT', messageId: 'tool-1', toolCallId: resolving, content: 'Saved.' })
      }

      emit({ type: 'TOOL_CALL_START', toolCallId, toolCallName: 'SaveNote' })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId,
        messageId: 'stored-1',
        userMessageId: 'user-1',
        outcome: {
          type: 'interrupt',
          interrupts: [{ id: `int-${toolCallId}`, reason: 'approval', toolCallId, message: 'Save it?' }],
        },
      })
    }

    next(pausesOn('run-1', 'call-1'))
    next(pausesOn('run-2', 'call-2', 'call-1'))
    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-3' })
      emit({ type: 'TOOL_CALL_RESULT', messageId: 'tool-2', toolCallId: 'call-2', content: 'Saved.' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-3', delta: 'Both saved!' })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-3', messageId: 'stored-1' })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save two notes')
    await chat.approve('call-1')
    await chat.approve('call-2')

    expect(chat.messages.map((message) => message.id)).toEqual(['user-1', 'stored-1'])
    expect(chat.messages[1]).toMatchObject({ content: 'Both saved!', status: 'complete' })
    expect(chat.messages[1].toolCalls.map((call) => [call.id, call.state])).toEqual([
      ['call-1', 'complete'],
      ['call-2', 'complete'],
    ])
  })

  it('answers the second pause on a stored row while its resume runs', async () => {
    const { connection, next } = transport()
    const held = deferred()

    const pausesOn = (runId: string, toolCallId: string) => (emit: (event: AgUiEvent) => void) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId })
      emit({ type: 'TOOL_CALL_START', toolCallId, toolCallName: 'SaveNote' })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId,
        messageId: 'stored-1',
        userMessageId: 'user-1',
        outcome: {
          type: 'interrupt',
          interrupts: [{ id: `int-${toolCallId}`, reason: 'approval', toolCallId, message: 'Save it?' }],
        },
      })
    }

    next(pausesOn('run-1', 'call-1'))
    next(pausesOn('run-2', 'call-2'))
    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save two notes')
    await chat.approve('call-1')

    const resuming = chat.approve('call-2')

    await settle()

    expect(chat.pendingApprovals).toEqual([])
    expect(chat.messages[1].status).toBe('approval_responded')

    held.resolve()
    await resuming
  })

  it('encodes a rejection as an unapproved resume entry', async () => {
    const { connection, next, requests } = transport()

    next(pauses)

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.reject('call-1')

    expect(requests[1].body.resume).toEqual([
      {
        interruptId: 'call-1',
        status: 'resolved',
        payload: { approved: false },
      },
    ])
  })

  it('refuses to resume with no decisions', async () => {
    const { connection, next, requests } = transport()

    next(pauses)

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.respond({})

    expect(requests).toHaveLength(1)
  })

  it('puts the decision back when the resume returns an approval mismatch', async () => {
    const { connection, next } = transport()
    const approvals = [{ id: 'call-1', tool: 'SaveNote', arguments: {}, reason: 'Still waiting.' }]

    next(pauses)
    next(() => {
      throw new ChatRequestError('Stale decision.', 409, {}, approvals, '', 'approval_mismatch')
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.reject('call-1')

    expect(chat.pendingApprovals).toEqual([expect.objectContaining({ id: 'call-1', reason: 'Still waiting.' })])
    expect(chat.error).toBe('Stale decision.')
    expect(chat.errorCode).toBe('approval_mismatch')
  })

  it('keeps the paused call answered when a mismatch names no approvals', async () => {
    const { connection, next } = transport()

    next(pauses)
    next(() => {
      throw new ChatRequestError('Stale decision.', 409, {}, [], '', 'approval_mismatch')
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.reject('call-1')

    expect(chat.messages.at(-2)?.status).not.toBe('complete')
    expect(chat.errorCode).toBe('approval_mismatch')
  })

  it('offers no retry for decisions the thread has moved past', async () => {
    const { connection, next } = transport()

    next(pauses)
    next(() => {
      throw new ChatRequestError('Stale decision.', 409, {}, [], '', 'approval_mismatch')
    })

    const chat = chatWith(sourceRef({ capabilities: { retry: true } }), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    expect(chat.can.retry).toBe(false)

    chat.clearError()

    expect(chat.can.retry).toBe(false)
  })

  it('offers no retry once a mismatch puts the pending approvals back', async () => {
    const { connection, next } = transport()
    const approvals = [{ id: 'call-1', tool: 'SaveNote', arguments: {}, reason: 'Still waiting.' }]

    next(pauses)
    next(() => {
      throw new ChatRequestError('Stale decision.', 409, {}, approvals, '', 'approval_mismatch')
    })

    const chat = chatWith(sourceRef({ capabilities: { retry: true } }), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    expect(chat.pendingApprovals).toHaveLength(1)
    expect(chat.can.retry).toBe(false)
  })

  /*
   * Another tab answered first. The server has nothing pending, so the call is settled by reading how that tab's
   * run answered it rather than left resuming until a reload.
   */
  it('settles a call another tab already answered from the thread’s stream', async () => {
    const server = threadServer()
    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 10,
        messages: [{ ...pausedPayloadMessage(), content: '' }],
        interrupts: [saveNoteInterrupt],
      }),
      { connection: server.connection },
    )

    await settleAttach(chat.store, server)

    server.refuse(new ChatRequestError('Stale decision.', 409, {}, [], '', 'approval_mismatch'))
    await chat.reject('call-1')
    await settle()

    expect(server.follows.map((request) => request.offset)).toEqual([10, 10])

    const replayed: AgUiEvent[] = []

    resumes({ messageId: 'stored-1' })((event) => replayed.push(event), {} as ChatConnectionRequest)
    server.write(11, replayed)

    expect(chat.messages[0].toolCalls[0]).toMatchObject({ id: 'call-1', state: 'complete', result: 'Saved notes.txt.' })
    expect(chat.messages[0].content).toBe('Saved!')
    expect(chat.errorCode).toBe('approval_mismatch')
  })

  it('never reads a 409 without the mismatch code as a mismatch', async () => {
    const { connection, next } = transport()
    const approvals = [{ id: 'call-9', tool: 'Other', arguments: {}, reason: null }]

    next(pauses)
    next(() => {
      throw new ChatRequestError('Busy.', 409, {}, approvals)
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.reject('call-1')

    expect(chat.pendingApprovals).toEqual([])
    expect(chat.errorCode).toBeNull()
  })

  it('reports the code when the run rejects a resume mid-stream', async () => {
    const { connection, next } = transport()

    next(pauses)
    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-2' })
      emit({
        type: 'RUN_ERROR',
        message: 'That call is not pending.',
        code: 'approval_mismatch',
      })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Save a note')
    await chat.approve('call-1')

    expect(chat.error).toBe('That call is not pending.')
    expect(chat.errorCode).toBe('approval_mismatch')
  })

  it('leaves the code null for a failure the server did not name', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'RUN_ERROR', message: 'An error occurred.' })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.error).toBe('An error occurred.')
    expect(chat.errorCode).toBeNull()
  })
})
