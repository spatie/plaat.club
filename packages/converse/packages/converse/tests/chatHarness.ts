/**
 * The doubles the store suites are written against: a scripted transport, a thread server, and the store read
 * through the names an adapter exposes, since an adapter only has to re-render when `subscribe` fires.
 */

import { createChat } from '../src/chat'
import type {
  AgUiEvent,
  AgUiInterrupt,
  AgUiMessage,
  ChatAcceptedTurn,
  ChatAttachRequest,
  ChatConnection,
  ChatConnectionRequest,
  ChatMessage,
  ChatRunRef,
  ChatSource,
  ChatStreamFrame,
} from '../src/types'

/** One run's script: emit what the server would have, then return. */
export type Script = (emit: (event: AgUiEvent) => void, request: ChatConnectionRequest) => void | Promise<void>

type Transport = {
  connection: ChatConnection
  /** Every request the store made, in order. */
  requests: ChatConnectionRequest[]
  /** Queue a script for the next run. Falls back to answering with "Hi". */
  next: (script: Script) => void
}

export function transport(): Transport {
  const requests: ChatConnectionRequest[] = []
  const scripts: Script[] = []

  const answer: Script = (emit) => {
    emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
    emit({
      type: 'TEXT_MESSAGE_CONTENT',
      messageId: 'assistant-1',
      delta: 'Hi',
    })
    emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
  }

  return {
    requests,
    next: (script) => scripts.push(script),
    connection: async (request) => {
      requests.push(request)

      await (scripts.shift() ?? answer)(request.onEvent, request)
    },
  }
}

/** The prompt a `RunAgentInput` body carries, or undefined for a resume. */
export function promptIn(request: ChatConnectionRequest): string | undefined {
  const messages = request.body.messages as { content?: string }[] | undefined

  return messages?.[0]?.content
}

/** A promise a test resolves by hand, to hold a run open. */
export function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve: () => void = () => {}
  const promise = new Promise<void>((done) => (resolve = done))

  return { promise, resolve }
}

/** A stored message, as a page prop would carry it. */
export function storedMessage(overrides: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id: 'stored-1',
    role: 'assistant',
    content: 'Stored.',
    reasoning: '',
    status: 'complete',
    toolCalls: [],
    approvals: [],
    citations: [],
    usage: null,
    error: null,
    provider: null,
    model: null,
    createdAt: '2026-01-01T00:00:00+00:00',
    parts: [{ type: 'text', text: 'Stored.' }],
    ...overrides,
  }
}

/** A stored message, as the server's payload carries it. */
export function payloadMessage(overrides: Partial<AgUiMessage> = {}): AgUiMessage {
  return { id: 'stored-1', role: 'assistant', content: 'Stored.', ...overrides }
}

/** A stored answer that announced a call the run then paused on. */
export function pausedPayloadMessage(): AgUiMessage {
  return payloadMessage({
    toolCalls: [{ id: 'call-1', type: 'function', function: { name: 'SaveNote', arguments: '{"id":7}' } }],
  })
}

/** The interrupt a payload carries for the paused call. */
export const saveNoteInterrupt: AgUiInterrupt = {
  id: 'call-1',
  reason: 'tool_approval',
  toolCallId: 'call-1',
  message: 'Save this?',
}

export function streamFrame(id: number, data: AgUiEvent): ChatStreamFrame {
  return { event: null, id: String(id), data: JSON.stringify(data) }
}

/** A connection that can also catch up on a thread, the way `createResumableConnection` does. */
export function attachable(script: (request: ChatAttachRequest) => void | Promise<void>): ChatConnection {
  const connection: ChatConnection = async () => {}

  connection.attach = async (request) => {
    await script(request)
  }

  return connection
}

/** Emit events as the thread's stream holds them: numbered from `first`, in order. */
export function emitFrames(request: { onEvent: ChatAttachRequest['onEvent'] }, events: AgUiEvent[], first = 0): void {
  events.forEach((event, index) => request.onEvent(event, streamFrame(first + index, event)))
}

/** A payload a test can replace, standing in for a prop the server re-renders. */
export type Payload = { current: ChatSource }

export function sourceRef(overrides: Partial<ChatSource> = {}): Payload {
  return { current: { endpoint: '/chat', ...overrides } }
}

type Options = Omit<Parameters<typeof createChat>[0], 'source'>

/**
 * The default transport for a test that never sends.
 *
 * `connection` is required, and the transcript tests have no use for one; a connection that refuses
 * says so, and turns a test that unexpectedly reaches the transport into a failure rather than a pass.
 */
export const unreachable: ChatConnection = () => {
  throw new Error('This test was not meant to reach the transport.')
}

/**
 * The store, read through the same names an adapter exposes.
 *
 * Each getter reads `getState()` afresh, which is what a re-render does.
 */
export function chatWith(source: Payload | ChatSource, options: Options = { connection: unreachable }) {
  const payload: Payload = 'current' in source ? (source as Payload) : { current: source as ChatSource }
  const store = createChat({ ...options, source: () => payload.current })

  return {
    store,
    get messages() {
      return store.getState().messages
    },
    get threadId() {
      return store.getState().threadId
    },
    get streaming() {
      return store.getState().streaming
    },
    get status() {
      return store.getState().status
    },
    get error() {
      return store.getState().error
    },
    get errorCode() {
      return store.getState().errorCode
    },
    get validationErrors() {
      return store.getState().validationErrors
    },
    get pendingApprovals() {
      return store.getState().pendingApprovals
    },
    get queued() {
      return store.getState().queued
    },
    get can() {
      return store.getState().can
    },
    get infiniteScrollProps() {
      return store.getState().infiniteScrollProps
    },
    send: store.send,
    respond: store.respond,
    approve: store.approve,
    reject: store.reject,
    retry: store.retry,
    cancel: store.cancel,
    clearError: store.clearError,
    syncSource: store.syncSource,
  }
}

/** Let queued listeners and pending promises run, as a render would. */
export function settle(): Promise<void> {
  return Promise.resolve()
}

/** Counts how often the store announced a change from this point on. */
export function changes(store: ReturnType<typeof createChat>): () => number {
  let calls = 0

  store.subscribe(() => {
    calls += 1
  })

  return () => calls
}

/** A run that stops to ask, pausing on one tool call. */
export const pauses: Script = (emit) => {
  emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
  emit({
    type: 'TOOL_CALL_START',
    toolCallId: 'call-1',
    toolCallName: 'SaveNote',
  })
  emit({
    type: 'RUN_FINISHED',
    threadId: 'conv-1',
    runId: 'run-1',
    outcome: {
      type: 'interrupt',
      interrupts: [
        {
          id: 'int-1',
          reason: 'approval',
          toolCallId: 'call-1',
          message: 'Save notes.txt?',
        },
      ],
    },
  })
}

/** The run an approval resumes: the paused call's result, then the answer, stored under `messageId`. */
export function resumes(finished: { messageId?: string } = {}): Script {
  return (emit) => {
    emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-2' })
    emit({ type: 'TOOL_CALL_RESULT', messageId: 'tool-1', toolCallId: 'call-1', content: 'Saved notes.txt.' })
    emit({ type: 'TEXT_MESSAGE_START', messageId: 'm-2', role: 'assistant' })
    emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-2', delta: 'Saved!' })
    emit({ type: 'TEXT_MESSAGE_END', messageId: 'm-2' })
    emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-2', ...finished })
  }
}

/**
 * The thread-stream transport as the server drives it: a send is answered with the turn it was accepted as (or
 * refused), and a follow is open until the server says nothing on the thread is live, the follow fails, or the
 * store aborts it, after which the thread is idle until the store follows it again.
 */
export function threadServer() {
  const sent: ChatConnectionRequest[] = []
  const follows: ChatAttachRequest[] = []
  const cancelled: ChatRunRef[] = []
  const replies: (() => Promise<ChatAcceptedTurn>)[] = []
  let follow: { state: 'idle' } | { state: 'open'; request: ChatAttachRequest; end: (error?: unknown) => void } = {
    state: 'idle',
  }
  let runs = 0

  const connection: ChatConnection = async (request) => {
    sent.push(request)

    const reply = replies.shift()

    if (reply === undefined) {
      runs += 1

      return { threadId: 'conv-1', runId: `run-${runs}`, offset: 0 }
    }

    // A send the store abandons is cut off, the way an aborted fetch is.
    return Promise.race([
      reply(),
      new Promise<never>((_resolve, reject) => {
        request.signal.addEventListener('abort', () => reject(request.signal.reason), { once: true })
      }),
    ])
  }

  connection.attach = (request) => {
    follows.push(request)

    return new Promise((resolve, reject) => {
      const end = (error?: unknown): void => {
        if (follow.state === 'open' && follow.request === request) {
          follow = { state: 'idle' }
        }

        if (error === undefined) {
          resolve()
        } else {
          reject(error)
        }
      }

      follow = { state: 'open', request, end }
      request.signal.addEventListener('abort', () => end(request.signal.reason), { once: true })
    })
  }

  connection.cancel = async (run) => {
    cancelled.push(run)
  }

  const open = () => {
    if (follow.state !== 'open') {
      throw new Error('Nothing is following the thread.')
    }

    return follow
  }

  return {
    connection,
    sent,
    follows,
    cancelled,
    /** Answer the next send with the turn it was accepted as. */
    accept: (turn: ChatAcceptedTurn) => replies.push(async () => turn),
    /** Answer the next send once the test lets it, with the turn it was accepted as. */
    acceptLater: (turn: ChatAcceptedTurn) => {
      const held = deferred()

      replies.push(async () => {
        await held.promise

        return turn
      })

      return held.resolve
    },
    /** Refuse the next send. */
    refuse: (error: Error) =>
      replies.push(async () => {
        throw error
      }),
    /** Write events to the thread, numbered from `first`. */
    write: (first: number, events: AgUiEvent[]) => emitFrames(open().request, events, first),
    /** Fail the follow, the way a read that keeps failing gives up. */
    fail: (error: Error) => open().end(error),
    /** Tell the follow where it caught up to, which ends it where nothing on the thread is live. */
    upToDate: (offset: number, live = false) => {
      const current = open()

      current.request.onUpToDate?.({ offset, live })

      if (!live) {
        current.end()
      }
    },
    /** Determine if a follow is open. */
    following: () => follow.state === 'open',
    follower: () => open().request,
  }
}

/** A run as the thread's stream carries it: the prompt it was sent with, its answer, and its end. */
export function turnEvents(runId: string, prompt: { id: string; clientId?: string; text: string }, answer: string) {
  const start: AgUiEvent =
    prompt.clientId === undefined
      ? { type: 'TEXT_MESSAGE_START', messageId: prompt.id, role: 'user' }
      : { type: 'TEXT_MESSAGE_START', messageId: prompt.id, role: 'user', clientMessageId: prompt.clientId }

  return {
    prompt: [
      start,
      { type: 'TEXT_MESSAGE_CONTENT', messageId: prompt.id, delta: prompt.text },
      { type: 'TEXT_MESSAGE_END', messageId: prompt.id },
    ] as AgUiEvent[],
    answer: [
      { type: 'RUN_STARTED', threadId: 'conv-1', runId },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: `${runId}-answer`, delta: answer },
    ] as AgUiEvent[],
    finished: { type: 'RUN_FINISHED', threadId: 'conv-1', runId } as AgUiEvent,
  }
}

/** The role and text of every message, which is what a reader sees. */
export function transcriptOf(messages: ChatMessage[]): [string, string][] {
  return messages.map((message) => [message.role, message.content])
}

/** Attach and let the catch-up end where the page's offset left it. */
export async function settleAttach(
  store: ReturnType<typeof createChat>,
  server: ReturnType<typeof threadServer>,
  offset?: number,
) {
  const caughtUp = store.attach()

  server.upToDate(offset ?? server.follower().offset)

  await caughtUp
}
