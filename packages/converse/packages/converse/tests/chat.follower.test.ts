/** Following the thread's stream while other runs and other tabs answer on it. */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ChatRequestError, ChatStreamStalledError, ChatThreadBusyError } from '../src/errors'
import * as converse from '../src/index'
import type { AgUiEvent, ChatAttachRequest, ChatConnection, ChatQueue, ChatRunRef, ChatSession } from '../src/types'
import {
  transport,
  promptIn,
  deferred,
  pausedPayloadMessage,
  saveNoteInterrupt,
  emitFrames,
  type Payload,
  sourceRef,
  chatWith,
  settle,
  threadServer,
  turnEvents,
  transcriptOf,
  settleAttach,
} from './chatHarness'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('the thread follower', () => {
  it('follows the thread from the offset the page names, catching up before it settles to ready', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 12 }), { connection: server.connection })

    const caughtUp = chat.store.attach()

    expect(server.follows.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 12]])
    expect(chat.status).toBe('resuming')

    // Past a gap the stream no longer holds, which moves the session all the same.
    server.upToDate(14)
    await caughtUp

    expect(chat.status).toBe('ready')
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 14 })
  })

  it('stops following once nothing is live, and picks the thread up again from where it read to', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection })

    await settleAttach(chat.store, server, 6)

    expect(server.following()).toBe(false)
    expect(chat.status).toBe('ready')

    const caughtUp = chat.store.sync()

    expect(server.follows.map((request) => [request.threadId, request.offset])).toEqual([
      ['conv-1', 3],
      ['conv-1', 6],
    ])

    server.upToDate(6)
    await caughtUp

    expect(server.following()).toBe(false)
  })

  it('shows another tab’s turn live, without this browser sending anything', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection })
    const other = turnEvents(
      'run-other',
      { id: 'stored-prompt', clientId: 'other-tab-user-1', text: 'From the other tab' },
      'Live',
    )

    await settleAttach(chat.store, server)
    void chat.store.sync()

    server.write(4, [...other.prompt, ...other.answer])

    expect(chat.status).toBe('streaming')
    expect(chat.store.getState().busy).toBe(true)
    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'From the other tab'],
      ['assistant', 'Live'],
    ])

    server.write(9, [other.finished])

    expect(chat.status).toBe('ready')
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 9 })
  })

  it('renders its own prompt once when the thread’s stream hands it back', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    server.accept({ threadId: 'conv-1', runId: 'run-7', offset: 3 })

    const sending = chat.send('Hello')

    await vi.waitFor(() => expect(server.following()).toBe(true))

    const local = chat.messages[0].id
    const own = turnEvents('run-7', { id: 'stored-user', clientId: local, text: 'Hello' }, 'Hi')

    server.write(4, [...own.prompt, ...own.answer, { ...own.finished, userMessageId: 'stored-user' } as AgUiEvent])
    await sending

    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Hello'],
      ['assistant', 'Hi'],
    ])
    expect(chat.messages[0].id).toBe('stored-user')
    expect(chat.status).toBe('ready')
    // The catch-up had ended, so the send followed the thread from where its run begins.
    expect(server.follows.map((request) => request.offset)).toEqual([3, 3])
  })

  it('keeps the run that follows its own finish out of its answer, even within the same tick', async () => {
    const server = threadServer()
    const onFinish = vi.fn()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection, onFinish })

    await settleAttach(chat.store, server)

    server.accept({ threadId: 'conv-1', runId: 'run-7', offset: 3 })

    const sending = chat.send('Hello')

    await vi.waitFor(() => expect(server.following()).toBe(true))

    const own = turnEvents('run-7', { id: 'stored-user', clientId: chat.messages[0].id, text: 'Hello' }, 'Hi')
    const next = turnEvents('run-8', { id: 'other-user', text: 'Queued elsewhere' }, 'Theirs')

    server.write(4, [...own.prompt, ...own.answer, own.finished, ...next.prompt, ...next.answer])
    await sending

    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Hello'],
      ['assistant', 'Hi'],
      ['user', 'Queued elsewhere'],
      ['assistant', 'Theirs'],
    ])
    expect(onFinish).toHaveBeenCalledWith(expect.objectContaining({ content: 'Hi' }))
    expect(chat.status).toBe('streaming')

    await chat.cancel()

    expect(server.cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-8' }])
  })

  it('holds what the stream delivers while its send waits to be accepted, then sorts it by run', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)
    void chat.store.sync()

    const accept = server.acceptLater({ threadId: 'conv-1', runId: 'run-2', offset: 3 })
    const sending = chat.send('Mine')

    await settle()

    const local = chat.messages[0].id
    const before = turnEvents('run-1', { id: 'p-1', text: 'Earlier' }, 'Earlier answer')
    const own = turnEvents('run-2', { id: 'p-2', clientId: local, text: 'Mine' }, 'My answer')

    // The other run ends at 2 and this turn's prompt lands at 4, all before the send's answer arrives.
    server.write(1, [before.answer[1], before.finished])
    server.write(4, own.prompt)

    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Mine'],
      ['assistant', ''],
    ])

    accept()
    await settle()
    await settle()

    server.write(7, [own.answer[1], own.finished])
    await sending

    expect(transcriptOf(chat.messages)).toEqual([
      ['assistant', 'Earlier answer'],
      ['user', 'Mine'],
      ['assistant', 'My answer'],
    ])
  })

  it('applies what it held in the order it arrived, even from a read that ended while it waited', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)
    void chat.store.sync()

    const accept = server.acceptLater({ threadId: 'conv-1', runId: 'run-mine', offset: 3 })
    const sending = chat.send('Mine')

    await settle()

    // Another tab's run, which the server then calls over without a frame of its own, all before the send's answer.
    server.write(1, [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-other', delta: 'Theirs' },
    ])
    server.upToDate(2)

    accept()
    await vi.waitFor(() => expect(server.following()).toBe(true))

    expect(server.follows.map((request) => request.offset)).toEqual([0, 0, 2])

    server.write(4, [
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-mine', delta: 'Ours' },
      { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-mine' },
    ])
    await sending

    expect(transcriptOf(chat.messages)).toEqual([
      ['assistant', 'Theirs'],
      ['user', 'Mine'],
      ['assistant', 'Ours'],
    ])

    // The other run settled where the stream said it had, so nothing of it is left to stop.
    await chat.cancel()

    expect(server.cancelled).toEqual([])
  })

  it('never renders what it held for a thread the page has switched away from', async () => {
    vi.stubGlobal('window', {})

    const server = threadServer()
    const source = sourceRef({ threadId: 'conv-1', offset: 0 })
    const chat = chatWith(source, { connection: server.connection })

    chat.store.mount()
    server.upToDate(0)
    await settle()
    void chat.store.sync()

    server.acceptLater({ threadId: 'conv-1', runId: 'run-mine', offset: 3 })

    const sending = chat.send('Mine')

    await settle()

    server.write(1, [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-other', delta: 'Held' },
    ])

    source.current = { endpoint: '/chat', threadId: 'conv-2', offset: 8 }
    chat.syncSource()
    await sending

    server.write(9, [
      { type: 'RUN_STARTED', threadId: 'conv-2', runId: 'run-two' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-two', delta: 'Fresh' },
      { type: 'RUN_FINISHED', threadId: 'conv-2', runId: 'run-two' },
    ])

    expect(server.follows.at(-1)).toMatchObject({ threadId: 'conv-2', offset: 8 })
    expect(transcriptOf(chat.messages)).toEqual([['assistant', 'Fresh']])
    expect(chat.status).toBe('ready')
  })

  it('starts following a new thread from where the turn that created it begins', async () => {
    const server = threadServer()
    const sessions: (ChatSession | null)[] = []
    const chat = chatWith(sourceRef(), {
      connection: server.connection,
      onSessionChange: (session) => sessions.push(session),
    })

    server.accept({ threadId: 'conv-new', runId: 'run-1', offset: 0 })

    const sending = chat.send('Hello')

    await vi.waitFor(() => expect(server.follows).toHaveLength(1))

    expect(server.follows[0]).toMatchObject({ threadId: 'conv-new', offset: 0 })
    expect(chat.threadId).toBe('conv-new')

    server.write(1, [{ type: 'RUN_FINISHED', threadId: 'conv-new', runId: 'run-1' }])
    await sending

    expect(sessions).toEqual([
      { threadId: 'conv-new', offset: 0 },
      { threadId: 'conv-new', offset: 1 },
    ])
  })

  it('stops its own run by the id the server accepted it as, before the stream has said anything', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    server.accept({ threadId: 'conv-1', runId: 'run-7', offset: 0 })

    const sending = chat.send('Hello')

    await settle()
    await chat.cancel()

    expect(server.cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-7' }])
    expect(chat.status).toBe('submitted')

    // The stop converges when the stream says so, not when the request returned.
    server.write(1, [
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Half' },
      { type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-7' },
    ])
    await sending

    expect(chat.status).toBe('ready')
    expect(chat.messages[1]).toMatchObject({ content: 'Half', status: 'cancelled' })
  })

  it('stops a send it waited on by the id the server accepted it as, and settles once the stream says so', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    const accept = server.acceptLater({ threadId: 'conv-1', runId: 'run-7', offset: 0 })
    const sending = chat.send('Hello')
    const stopping = chat.cancel()

    await settle()

    expect(server.cancelled).toEqual([])

    accept()
    await stopping

    // Accepted, stopped, and still waiting on a stream that has not said a word about the run.
    expect(server.cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-7' }])
    expect(chat.status).toBe('submitted')

    await vi.waitFor(() => expect(server.following()).toBe(true))

    server.write(1, [{ type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-7' }])
    await sending

    expect(chat.status).toBe('ready')
    expect(chat.messages[1]).toMatchObject({ status: 'cancelled' })
  })

  it('stops another tab’s run by the id its stream named', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)
    void chat.store.sync()

    server.write(1, [{ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' }])
    await chat.cancel()

    expect(server.cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-other' }])
  })

  it('sends a message typed during another tab’s turn once that turn ends', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })
    const other = turnEvents('run-other', { id: 'p-1', text: 'Theirs' }, 'Their answer')

    await settleAttach(chat.store, server)
    void chat.store.sync()

    server.write(1, [...other.prompt, ...other.answer])

    expect(await chat.send('Mine')).toBe(true)
    expect(server.sent).toEqual([])
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Mine'])

    server.accept({ threadId: 'conv-1', runId: 'run-2', offset: 6 })
    server.write(6, [other.finished])

    await vi.waitFor(() => expect(server.sent).toHaveLength(1))

    expect(promptIn(server.sent[0])).toBe('Mine')
    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Theirs'],
      ['assistant', 'Their answer'],
      ['user', 'Mine'],
      ['assistant', ''],
    ])
  })

  it('waits on a run the server calls live before it has written anything, and sends once it is not', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 5 }), { connection: server.connection })

    void chat.store.attach()
    server.upToDate(5, true)

    expect(chat.status).toBe('streaming')

    await chat.send('Mine')

    expect(server.sent).toEqual([])

    server.upToDate(5, false)

    await vi.waitFor(() => expect(server.sent).toHaveLength(1))
  })

  it('reports a follow that fails with nothing of its own on the wire as the store’s error', async () => {
    const errors: Error[] = []
    const failure = new ChatRequestError('This action is unauthorized.', 403)
    const connection: ChatConnection = async () => {}

    connection.attach = async () => {
      throw failure
    }

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), {
      connection,
      onError: (error) => errors.push(error),
    })

    await chat.store.attach()

    expect(chat.status).toBe('error')
    expect(chat.error).toBe('This action is unauthorized.')
    expect(errors).toEqual([failure])
  })

  it('fails the answer of another tab’s run on screen when the follow gives up on it', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)
    void chat.store.sync()

    server.write(1, [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Half' },
    ])
    server.fail(new ChatStreamStalledError(6))

    await vi.waitFor(() => expect(chat.status).toBe('error'))

    expect(chat.messages[0]).toMatchObject({
      content: 'Half',
      status: 'error',
      error: 'The chat stream stopped responding.',
    })
    expect(chat.errorCode).toBeNull()
  })

  it('settles its turn when a follow ends with nothing more to read', async () => {
    const connection: ChatConnection = async () => ({ threadId: 'conv-1', runId: 'run-1', offset: 0 })

    connection.attach = async (request) => {
      emitFrames(request, [{ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'All of it' }], 1)
    }

    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection })

    await chat.send('Hello')

    expect(chat.status).toBe('ready')
    expect(chat.messages.map((message) => message.content)).toEqual(['Hello', 'All of it'])
  })

  it('fails the turn waiting on the thread’s stream when the follow fails', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    const sending = chat.send('Hello')

    await vi.waitFor(() => expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 0 }))

    server.fail(new ChatStreamStalledError(6))
    await sending

    expect(chat.status).toBe('error')
    expect(chat.error).toBe('The chat stream stopped responding.')
    expect(chat.messages[1]).toMatchObject({ status: 'error', error: 'The chat stream stopped responding.' })
  })

  it('stops reading the thread on teardown, leaving the run on it alone', async () => {
    vi.stubGlobal('window', {})

    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), { connection: server.connection })
    const unmount = chat.store.mount()

    server.write(1, [{ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' }])

    expect(chat.status).toBe('streaming')

    unmount()

    expect(server.follows[0].signal.aborted).toBe(true)
    expect(server.cancelled).toEqual([])
    expect(chat.status).toBe('ready')
  })

  it('follows the thread a re-rendered payload switches to, while mounted', async () => {
    vi.stubGlobal('window', {})

    const server = threadServer()
    const source = sourceRef({ threadId: 'conv-1', offset: 0 })
    const chat = chatWith(source, { connection: server.connection })

    chat.store.mount()

    source.current = { endpoint: '/chat', threadId: 'conv-2', offset: 8 }
    chat.syncSource()

    expect(server.follows.map((request) => [request.threadId, request.offset, request.signal.aborted])).toEqual([
      ['conv-1', 0, true],
      ['conv-2', 8, false],
    ])
  })

  it('renders a pruned thread from the snapshot its stream answers with, the pause included', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 2, capabilities: { approvals: true } }), {
      connection: server.connection,
    })

    void chat.store.attach()

    server.write(9, [{ type: 'MESSAGES_SNAPSHOT', messages: [pausedPayloadMessage()] }])
    server.write(9, [
      {
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'snapshot',
        outcome: { type: 'interrupt', interrupts: [saveNoteInterrupt] },
      },
    ])
    server.upToDate(9)

    expect(chat.status).toBe('ready')
    expect(chat.messages.map((message) => [message.id, message.status])).toEqual([['stored-1', 'awaiting_approval']])
    expect(chat.pendingApprovals.map((approval) => approval.id)).toEqual(['call-1'])
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 9 })
  })

  it('moves the session back to a snapshot behind it, so the next read starts from the restarted thread', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 12 }), { connection: server.connection })

    const caughtUp = chat.store.attach()

    server.write(3, [{ type: 'MESSAGES_SNAPSHOT', messages: [] }])

    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 3 })

    server.upToDate(3, false)
    await caughtUp

    void chat.store.sync()

    expect(server.follows.map((request) => request.offset)).toEqual([12, 3])
  })

  it('resolves another tab’s replayed approval on the message that paused, in this tab too', async () => {
    const server = threadServer()
    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 4,
        messages: [pausedPayloadMessage()],
        interrupts: [saveNoteInterrupt],
        capabilities: { approvals: true },
      }),
      { connection: server.connection },
    )

    await settleAttach(chat.store, server, 4)

    expect(chat.pendingApprovals.map((approval) => approval.id)).toEqual(['call-1'])

    void chat.store.sync()
    server.write(5, [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-resume' },
      { type: 'TOOL_CALL_RESULT', messageId: 'tool-1', toolCallId: 'call-1', content: 'Saved.' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-2', delta: 'Done.' },
      { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-resume' },
    ])

    expect(chat.pendingApprovals).toEqual([])
    expect(chat.messages[0].toolCalls[0]).toMatchObject({ state: 'complete', result: 'Saved.' })
    expect(chat.messages.map((message) => message.content)).toEqual(['Stored.', 'Done.'])
  })
})

describe('a thread another run is answering', () => {
  const busy = new ChatThreadBusyError('conv-1', 'run-other', 3)
  const other = turnEvents('run-other', { id: 'stored-prompt', text: 'Other question' }, 'Other answer')

  it('waits for that run on the thread’s stream, then sends the turn it refused, after that answer', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection: server.connection })

    server.refuse(busy)

    const kept = await chat.send('Mine')

    expect(kept).toBe(true)
    expect(server.follows.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Mine'])
    expect(chat.status).toBe('streaming')

    server.accept({ threadId: 'conv-1', runId: 'run-mine', offset: 9 })
    server.write(4, [...other.prompt, ...other.answer, other.finished])

    await vi.waitFor(() => expect(server.sent).toHaveLength(2))

    expect(promptIn(server.sent[1])).toBe('Mine')
    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Other question'],
      ['assistant', 'Other answer'],
      ['user', 'Mine'],
      ['assistant', ''],
    ])
    expect(chat.error).toBeNull()
  })

  it('prefers the offset it already read the thread to over the one the refusal names', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 1 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    server.refuse(busy)
    await chat.send('Mine')

    expect(server.follows.map((request) => request.offset)).toEqual([1, 1])
  })

  it('sends right away where the run settled before the refusal arrived', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection })

    await settleAttach(chat.store, server)
    void chat.store.sync()

    server.write(4, [...other.prompt, ...other.answer, other.finished])
    server.refuse(busy)
    await chat.send('Mine')

    await vi.waitFor(() => expect(server.sent).toHaveLength(2))

    expect(promptIn(server.sent[1])).toBe('Mine')
  })

  it('drops the refused turn under drop, and says so', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', capabilities: { retry: true } }), {
      connection: server.connection,
      queue: 'drop',
    })

    server.refuse(busy)

    expect(await chat.send('Mine')).toBe(false)

    server.write(4, [...other.prompt, ...other.answer, other.finished])

    expect(chat.status).toBe('ready')
    expect(server.sent).toHaveLength(1)
    expect(chat.messages.map((message) => message.content)).toEqual(['Other question', 'Other answer'])
    expect(chat.can.retry).toBe(false)
  })

  it('stops that run first under interrupt, then sends the turn', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection: server.connection, queue: 'interrupt' })

    server.refuse(busy)
    await chat.send('Mine')

    expect(server.cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-other' }])

    server.write(4, [{ type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-other' }])

    await vi.waitFor(() => expect(server.sent).toHaveLength(2))

    expect(promptIn(server.sent[1])).toBe('Mine')
  })

  /*
   * A connection written by hand refuses a turn the same way the shipped one does, which takes
   * the error classes the package exports rather than ones it keeps to itself.
   */
  it('rejoins the thread for a custom connection that refuses with the exported error', async () => {
    const followed: ChatAttachRequest[] = []

    const connection: ChatConnection = async () => {
      throw new converse.ChatThreadBusyError('conv-1', 'run-other', 3)
    }

    connection.attach = (request) => {
      followed.push(request)

      return new Promise((resolve) => request.signal.addEventListener('abort', () => resolve(), { once: true }))
    }

    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection })

    expect(await chat.send('Mine')).toBe(true)
    expect(chat.status).toBe('streaming')
    expect(chat.error).toBeNull()
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Mine'])
    expect(followed.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
    expect(new converse.ChatStreamStalledError(3)).toBeInstanceOf(ChatStreamStalledError)

    chat.store.detach()
  })

  it('fails the turn where the transport cannot follow the thread', async () => {
    const connection: ChatConnection = async () => {
      throw busy
    }

    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection })

    expect(await chat.send('Mine')).toBe(true)
    expect(chat.status).toBe('error')
    expect(chat.error).toBe('The chat thread is already answering another turn.')
  })
})

describe('an approval answered while another run holds the thread', () => {
  const busy = new ChatThreadBusyError('conv-1', 'run-other', 3)
  const other = turnEvents('run-other', { id: 'stored-prompt', text: 'Other question' }, 'Other answer')

  function pausedSource(): Payload {
    return sourceRef({
      threadId: 'conv-1',
      messages: [pausedPayloadMessage()],
      interrupts: [saveNoteInterrupt],
      capabilities: { approvals: true },
    })
  }

  it('waits for that run, then sends the reply it refused', async () => {
    const server = threadServer()
    const chat = chatWith(pausedSource(), { connection: server.connection })

    server.refuse(busy)
    await chat.approve('call-1')

    expect(chat.status).toBe('streaming')
    expect(chat.pendingApprovals).toEqual([])
    expect(chat.queued).toEqual([])

    server.accept({ threadId: 'conv-1', runId: 'run-mine', offset: 9 })
    server.write(4, [...other.prompt, ...other.answer, other.finished])

    await vi.waitFor(() => expect(server.sent).toHaveLength(2))

    expect(server.sent[1].body.resume).toEqual([
      { interruptId: 'call-1', status: 'resolved', payload: { approved: true } },
    ])
    expect(chat.error).toBeNull()
  })

  it('puts the approvals back under drop, so they can be answered again', async () => {
    const server = threadServer()
    const chat = chatWith(pausedSource(), { connection: server.connection, queue: 'drop' })

    server.refuse(busy)
    await chat.approve('call-1')

    server.write(4, [...other.prompt, ...other.answer, other.finished])

    expect(chat.status).toBe('ready')
    expect(server.sent).toHaveLength(1)
    expect(chat.pendingApprovals.map((approval) => approval.id)).toEqual(['call-1'])
    expect(chat.messages[0].status).toBe('awaiting_approval')
  })

  it('lets the run that held the thread resolve the approvals it put back', async () => {
    const server = threadServer()
    const chat = chatWith(pausedSource(), { connection: server.connection, queue: 'drop' })

    server.refuse(busy)
    await chat.approve('call-1')

    server.write(4, [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-other' },
      { type: 'TOOL_CALL_RESULT', messageId: 'tool-1', toolCallId: 'call-1', content: 'Saved.' },
      { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-other' },
    ])

    expect(chat.pendingApprovals).toEqual([])
    expect(chat.messages[0].toolCalls[0]).toMatchObject({ state: 'complete', result: 'Saved.' })
  })

  it('puts the approvals back where the transport cannot follow the thread', async () => {
    const connection: ChatConnection = async () => {
      throw busy
    }

    const chat = chatWith(pausedSource(), { connection })

    await chat.approve('call-1')

    expect(chat.status).toBe('error')
    expect(chat.pendingApprovals.map((approval) => approval.id)).toEqual(['call-1'])
  })
})

describe('a message sent while this browser is busy', () => {
  it('reports a message dropped under drop as not kept', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection, queue: 'drop' })
    const running = chat.send('First')

    await settle()

    expect(await chat.send('Second')).toBe(false)

    held.resolve()
    await running
  })

  it('reads the behavior afresh each time a message arrives busy', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()
    let queue: ChatQueue = 'drop'

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection, queue: () => queue })
    const running = chat.send('First')

    await settle()

    expect(await chat.send('Second')).toBe(false)

    queue = 'queue'

    expect(await chat.send('Third')).toBe(true)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Third'])

    held.resolve()
    await running
    await vi.waitFor(() => expect(requests.map(promptIn)).toEqual(['First', 'Third']))
  })

  it('stops the live run on the server under interrupt, then sends', async () => {
    const { connection, next, requests } = transport()
    const cancelled: ChatRunRef[] = []
    const stopped = deferred()

    connection.cancel = async (run) => {
      cancelled.push(run)
      stopped.resolve()
    }

    next(async (emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm1', delta: 'Half' })

      await stopped.promise

      emit({ type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-1' })
    })

    const chat = chatWith(sourceRef(), { connection, queue: 'interrupt' })
    const running = chat.send('First')

    await settle()
    await chat.send('Second')
    await running

    expect(cancelled).toEqual([{ threadId: 'conv-1', runId: 'run-1' }])
    expect(requests.map(promptIn)).toEqual(['First', 'Second'])
    expect(requests[0].signal.aborted).toBe(false)
    expect(chat.messages[1].status).toBe('cancelled')
  })
})
