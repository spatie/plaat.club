/** Catching up on a thread: replaying its stream without repeating a frame, and syncing what the page carries. */

import { describe, expect, it } from 'vitest'
import type { AgUiEvent, ChatAttachRequest, ChatConnection, ChatPayload } from '../src/types'
import {
  transport,
  deferred,
  payloadMessage,
  pausedPayloadMessage,
  saveNoteInterrupt,
  streamFrame,
  attachable,
  emitFrames,
  sourceRef,
  chatWith,
  settle,
} from './chatHarness'

describe('resumable replay', () => {
  it('drops overlapping frame ids before the reducer mutates the message again', async () => {
    const events: AgUiEvent[] = [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Hi' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Hi' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: ' there' },
      { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' },
    ]

    const connection: ChatConnection = async (request) => {
      request.onEvent(events[0], streamFrame(0, events[0]))
      request.onEvent(events[1], streamFrame(1, events[1]))
      request.onEvent(events[2], streamFrame(1, events[2]))
      request.onEvent(events[3], streamFrame(2, events[3]))
      request.onEvent(events[4], streamFrame(3, events[4]))
    }

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.messages[1].content).toBe('Hi there')
  })

  // A transport that streams each turn itself may number each turn's frames from the start again.
  it('reads the frame ids of a turn a transport streamed itself against that turn alone', async () => {
    let turns = 0

    const connection: ChatConnection = async (request) => {
      turns += 1

      const event: AgUiEvent = { type: 'TEXT_MESSAGE_CONTENT', messageId: `m-${turns}`, delta: `Answer ${turns}` }

      request.onEvent(event, streamFrame(0, event))
    }

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('First')
    await chat.send('Second')

    expect(chat.messages.map((message) => message.content)).toEqual(['First', 'Answer 1', 'Second', 'Answer 2'])
  })

  /*
   * The two halves of the same problem, together: the watermark drops the frames a reconnect
   * re-delivers, and the reducer treats a completed message as authoritative for anything that
   * gets past it. A reader that re-read the whole run renders one answer, not two.
   */
  it('renders one clean message when a reconnect replays the frames it already applied', async () => {
    const events: AgUiEvent[] = [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
      { type: 'STEP_STARTED', stepName: '1' },
      { type: 'TEXT_MESSAGE_START', messageId: 'm-1', role: 'assistant' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Hi' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: ' there' },
      { type: 'TEXT_MESSAGE_END', messageId: 'm-1' },
      { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' },
    ]

    const connection: ChatConnection = async (request) => {
      events.forEach((event, seq) => request.onEvent(event, streamFrame(seq, event)))

      // The reconnect that re-reads the run. Same frames, same ids.
      events.forEach((event, seq) => request.onEvent(event, streamFrame(seq, event)))
    }

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.messages[1].content).toBe('Hi there')
    expect(chat.messages[1].parts).toEqual([{ type: 'text', text: 'Hi there' }])
  })

  /*
   * The one case where replace beats append. A snapshot is the server's own transcript of the thread, so
   * the half answer the deltas had accumulated and the page the payload was rendered with are both in it
   * already — folding it in would render the turn twice.
   */
  it('replaces the rendered transcript with a messages snapshot rather than appending to it', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Half an ans',
      })
      emit({
        type: 'MESSAGES_SNAPSHOT',
        messages: [
          { id: 'stored-user', role: 'user', content: 'Hello' },
          {
            id: 'stored-answer',
            role: 'assistant',
            content: 'The whole answer.',
          },
        ],
      })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
    })

    const chat = chatWith(sourceRef({ messages: [payloadMessage({ id: 'stale' })] }), { connection })

    await chat.send('Hello')

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-user', 'stored-answer'])
    expect(chat.messages.map((message) => message.content)).toEqual(['Hello', 'The whole answer.'])
  })

  it('renders hydrated data without interactive controls while resuming', () => {
    const { connection } = transport()
    const chat = chatWith(
      sourceRef({
        messages: [pausedPayloadMessage()],
        interrupts: [saveNoteInterrupt],
        capabilities: { approvals: true },
        threadId: 'conv-1',
        offset: 4,
      }),
      { connection },
    )

    expect(chat.status).toBe('resuming')
    expect(chat.streaming).toBe(false)
    expect(chat.messages).toHaveLength(1)
    expect(chat.messages[0].status).toBe('awaiting_approval')
    expect(chat.pendingApprovals).toEqual([])
    expect(chat.can).toEqual({
      approve: false,
      stream: false,
      retry: false,
    })
  })
})

describe('attach', () => {
  it('catches up on a live run, reporting resuming until its first frame', async () => {
    const seen: string[] = []

    const connection = attachable((request) => {
      seen.push(chat.status)

      // A run's first frames are the prompt: the user row is written before the turn runs, so a read
      // from before it reconstructs the whole exchange.
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        {
          type: 'TEXT_MESSAGE_START',
          messageId: 'user-row',
          role: 'user',
        },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'user-row',
          delta: 'Hello',
        },
        { type: 'TEXT_MESSAGE_END', messageId: 'user-row' },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'Hi there',
        },
      ])

      seen.push(chat.status)

      emitFrames(request, [{ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' }], 5)
    })

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 0 }), {
      connection,
    })

    await chat.store.attach()

    expect(seen).toEqual(['resuming', 'streaming'])
    expect(chat.status).toBe('ready')
    expect(chat.messages.map((message) => [message.role, message.content])).toEqual([
      ['user', 'Hello'],
      ['assistant', 'Hi there'],
    ])
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 5 })
  })

  /*
   * A settled or pruned run has no frames left to replay, so the route answers with the thread itself.
   * Both paths have to land in the same rendered transcript — and the snapshot is the whole of it, which
   * is why it replaces rather than appends.
   */
  it('renders the snapshot and ends when the thread has already settled', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-9' },
        {
          type: 'MESSAGES_SNAPSHOT',
          messages: [
            { id: 'stored-user', role: 'user', content: 'Hello' },
            {
              id: 'stored-answer',
              role: 'assistant',
              content: 'The whole answer.',
            },
          ],
        },
        { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-9' },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
        messages: [payloadMessage({ id: 'stale' })],
      }),
      {
        connection,
      },
    )

    await chat.store.attach()

    // No empty answer under it: the catch-up opened a turn it never had anything to put in.
    expect(chat.messages.map((message) => message.id)).toEqual(['stored-user', 'stored-answer'])
    expect(chat.messages[1].content).toBe('The whole answer.')
    expect(chat.status).toBe('ready')
  })

  /*
   * A settled thread can still be paused: a turn that stopped on an approval is stored with the pause
   * unanswered, and the snapshot's `RUN_FINISHED` carries the interrupt. It arrives after the snapshot
   * replaced what was rendered, so the answer this run holds is empty — decorating that with the approval
   * puts approve/deny under a bubble with nothing in it, while the call that is waiting reads as complete
   * in the transcript above. The pause belongs to the stored message.
   */
  it('renders a paused turn from a snapshot, on the message that announced the call', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-9' },
        {
          type: 'MESSAGES_SNAPSHOT',
          messages: [
            {
              id: 'stored-user',
              role: 'user',
              content: 'Delete it.',
            },
            {
              id: 'stored-answer',
              role: 'assistant',
              content: 'I need a decision first.',
              toolCalls: [
                {
                  id: 'call-1',
                  type: 'function',
                  function: {
                    name: 'DeleteNote',
                    arguments: '{"id":7}',
                  },
                },
              ],
            },
          ],
        },
        {
          type: 'RUN_FINISHED',
          threadId: 'conv-1',
          runId: 'run-9',
          outcome: {
            type: 'interrupt',
            interrupts: [
              {
                id: 'call-1',
                reason: 'approval_required',
                message: 'Delete note 7?',
                toolCallId: 'call-1',
                metadata: {
                  kind: 'approval',
                  toolName: 'DeleteNote',
                  input: { id: 7 },
                },
              },
            ],
          },
        },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
        capabilities: { approvals: true },
      }),
      {
        connection,
      },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-user', 'stored-answer'])
    expect(chat.messages[1]).toMatchObject({
      status: 'awaiting_approval',
      approvals: [
        {
          id: 'call-1',
          tool: 'DeleteNote',
          arguments: { id: 7 },
          reason: 'Delete note 7?',
        },
      ],
    })
    expect(chat.messages[1].toolCalls[0].state).toBe('awaiting_approval')
    expect(chat.pendingApprovals).toEqual([
      {
        id: 'call-1',
        tool: 'DeleteNote',
        arguments: { id: 7 },
        reason: 'Delete note 7?',
      },
    ])
    expect(chat.can.approve).toBe(true)
  })

  /*
   * The reload that lands mid-answer. The run's frames are the only place its answer exists — nothing is
   * stored until the turn settles — so the page names an offset from before the run, and the read renders
   * everything after it.
   */
  it('renders the whole answer when a page mounts mid-run', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        {
          type: 'TEXT_MESSAGE_START',
          messageId: 'user-row',
          role: 'user',
        },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'user-row',
          delta: 'Tell me everything.',
        },
        { type: 'TEXT_MESSAGE_END', messageId: 'user-row' },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'The beginning, ',
        },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'the middle, ',
        },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'and the end.',
        },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
      }),
      {
        connection,
      },
    )

    expect(chat.status).toBe('resuming')

    await chat.store.attach()

    expect(chat.messages.map((message) => [message.role, message.content])).toEqual([
      ['user', 'Tell me everything.'],
      ['assistant', 'The beginning, the middle, and the end.'],
    ])
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 6 })
  })

  /*
   * The same replay against a run that has already settled, whose rows the page payload carries. Reading
   * from frame one hands the store the prompt and the answer a second time: the prompt is recognised by
   * the stored id it was written with, and the answer adopts its row when the run finishes, which retires
   * the live turn instead of rendering the exchange twice.
   */
  it('does not double-apply a replayed run the page payload already holds', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        {
          type: 'TEXT_MESSAGE_START',
          messageId: 'stored-user',
          role: 'user',
        },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'stored-user',
          delta: 'Hello',
        },
        { type: 'TEXT_MESSAGE_END', messageId: 'stored-user' },
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'The whole answer.',
        },
        {
          type: 'RUN_FINISHED',
          threadId: 'conv-1',
          runId: 'run-1',
          messageId: 'stored-answer',
          userMessageId: 'stored-user',
        },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        messages: [
          payloadMessage({
            id: 'stored-user',
            role: 'user',
            content: 'Hello',
          }),
          payloadMessage({
            id: 'stored-answer',
            content: 'The whole answer.',
          }),
        ],
        offset: 0,
      }),
      { connection },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-user', 'stored-answer'])
    expect(chat.messages.map((message) => message.content)).toEqual(['Hello', 'The whole answer.'])
  })

  /*
   * Whether to follow is the server's question to answer: a payload with no offset gave the page nothing to
   * follow the thread from.
   */
  it('attaches to nothing when the payload names no offset', async () => {
    const attached: ChatAttachRequest[] = []

    const connection = attachable((request) => {
      attached.push(request)
    })

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: null }), {
      connection,
    })

    // Not even `resuming` to begin with: the payload already said there is nothing to catch up on.
    expect(chat.status).toBe('ready')

    await chat.store.attach()

    expect(attached).toEqual([])
    expect(chat.status).toBe('ready')
    expect(chat.store.getState().session).toBeNull()
  })

  it('follows the thread from the offset the payload names', async () => {
    const attached: ChatAttachRequest[] = []

    const connection = attachable((request) => {
      attached.push(request)
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 7,
      }),
      { connection },
    )

    // An offset to catch up from is reason enough to hold the composer before a frame has arrived.
    expect(chat.status).toBe('resuming')

    await chat.store.attach()

    expect(attached.map((request) => request.threadId)).toEqual(['conv-1'])
    expect(attached[0].offset).toBe(7)
  })

  // A run nobody can follow must not leave the composer disabled forever.
  it('lets go of resuming when the transport cannot catch up', async () => {
    const { connection } = transport()

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
      }),
      {
        connection,
      },
    )

    expect(chat.status).toBe('resuming')

    await chat.store.attach()

    expect(chat.status).toBe('ready')
  })

  // A compile-time assertion: `tsc --noEmit` reads `tests/`, so the annotation is the test.
  it('lets a payload that names no offset be typed as one', () => {
    const legacy: ChatPayload = {
      threadId: null,
      endpoint: '/chat',
      messages: [],
      transcriptPath: null,
      capabilities: {},
    }

    expect(legacy.offset).toBeUndefined()
  })
})

describe('sync', () => {
  it('picks up a turn another tab sent and follows it while it is live, without holding the composer', async () => {
    const attached: ChatAttachRequest[] = []
    const seen: string[] = []

    const connection = attachable((request) => {
      attached.push(request)

      if (attached.length === 1) {
        request.onUpToDate?.({ offset: 2, live: false })

        return
      }

      seen.push(chat.status)

      emitFrames(
        request,
        [
          { type: 'TEXT_MESSAGE_START', messageId: 'user-row', role: 'user' },
          { type: 'TEXT_MESSAGE_CONTENT', messageId: 'user-row', delta: 'From the other tab' },
          { type: 'TEXT_MESSAGE_END', messageId: 'user-row' },
          { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-2' },
        ],
        3,
      )
      request.onUpToDate?.({ offset: 6, live: true })

      seen.push(chat.status)

      emitFrames(
        request,
        [
          { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-2', delta: 'Hi' },
          { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-2' },
        ],
        7,
      )
      request.onUpToDate?.({ offset: 8, live: false })
    })

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 2 }), { connection })

    await chat.store.attach()
    await settle()

    expect(chat.status).toBe('ready')

    await chat.store.sync()
    await settle()

    expect(attached.map((request) => request.offset)).toEqual([2, 2])
    expect(seen).toEqual(['ready', 'streaming'])
    expect(chat.status).toBe('ready')
    expect(chat.messages.map((message) => [message.role, message.content])).toEqual([
      ['user', 'From the other tab'],
      ['assistant', 'Hi'],
    ])
    expect(chat.store.getState().session).toEqual({ threadId: 'conv-1', offset: 8 })
  })

  it('does not open a second read while one is in flight', async () => {
    const reading = deferred()
    const attached: ChatAttachRequest[] = []

    const connection = attachable(async (request) => {
      attached.push(request)
      request.onUpToDate?.({ offset: 2, live: true })

      await reading.promise
    })

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 2 }), { connection })

    await chat.store.sync()
    await chat.store.sync()

    expect(attached).toHaveLength(1)

    reading.resolve()
  })

  it('reads nothing without a thread to read', async () => {
    const attached: ChatAttachRequest[] = []

    const connection = attachable((request) => {
      attached.push(request)
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.store.sync()

    expect(attached).toEqual([])
    expect(chat.status).toBe('ready')
  })
})
