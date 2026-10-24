// @vitest-environment jsdom
//
// A DOM only for its `window`: the mount-time catch-up is client-only, so a
// suite that runs without one is testing the server's half of the branch. The
// SSR half has its own file, `useChatSsr.test.ts`, which deliberately has none.

/**
 * What the Vue adapter adds, and nothing else.
 *
 * The store's own rules live in `@laravel/converse`'s `store.test.ts`, against the bare store.
 * What is left here is the part Vue is responsible for: a payload read through `computed` so a
 * replaced prop moves the screen with no store notification, the `watch` that tells the store a
 * new thread arrived, and the scope that stops a run when the component goes away.
 */

import type {
  AgUiEvent,
  AgUiMessage,
  ChatAttachRequest,
  ChatConnection,
  ChatConnectionRequest,
  ChatQueue,
  ChatSession,
  ChatSource,
} from '@laravel/converse'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref, watch, type Ref } from 'vue'
import { useChat, type UseChatReturn } from '../src/composables/useChat'
import { ChatRequestError, ChatStreamStalledError, ChatThreadBusyError } from '../src/index'
import type { UseChatOptions } from '../src/types'

/* -------------------------------------------------------------------------- */
/* Harness                                                                     */
/* -------------------------------------------------------------------------- */

/** One run's script: emit what the server would have, then return. */
type Script = (emit: (event: AgUiEvent) => void, request: ChatConnectionRequest) => void | Promise<void>

type Transport = {
  connection: ChatConnection
  /** Every request the composable made, in order. */
  requests: ChatConnectionRequest[]
  /** Queue a script for the next run. Falls back to answering with "Hi". */
  next: (script: Script) => void
}

function transport(): Transport {
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

/** A promise a test resolves by hand, to hold a run open. */
function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve: () => void = () => {}
  const promise = new Promise<void>((done) => (resolve = done))

  return { promise, resolve }
}

/** A stored message, as the server's payload carries it. */
function payloadMessage(overrides: Partial<AgUiMessage> = {}): AgUiMessage {
  return { id: 'stored-1', role: 'assistant', content: 'Stored.', ...overrides }
}

/** A connection that can also catch up on a thread, recording what it was asked to attach to. */
function attachable(attached: ChatAttachRequest[]): ChatConnection {
  const connection: ChatConnection = async () => {}

  connection.attach = async (request) => {
    attached.push(request)

    // A tick before the first frame, the way a request is: the composable returns while the catch-up is
    // still in flight.
    await Promise.resolve()

    request.onEvent(
      {
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Caught up',
      },
      { event: null, id: '4', data: '' },
    )
  }

  return connection
}

/** Let a catch-up that is already in flight finish, as a render would. */
function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

const scopes: ReturnType<typeof effectScope>[] = []

/**
 * `useChat` in its own scope, so its computeds and its `onScopeDispose` behave
 * the way they do inside a component without mounting one.
 */
function chatWith(source: Ref<ChatSource> | ChatSource, options: UseChatOptions = {}): UseChatReturn {
  const scope = effectScope()

  scopes.push(scope)

  return scope.run(() => useChat(source, options)) as UseChatReturn
}

function sourceRef(overrides: Partial<ChatSource> = {}): Ref<ChatSource> {
  return ref<ChatSource>({ endpoint: '/chat', ...overrides })
}

afterEach(() => {
  while (scopes.length > 0) {
    scopes.pop()?.stop()
  }

  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

/* -------------------------------------------------------------------------- */
/* The transcript                                                              */
/* -------------------------------------------------------------------------- */

describe('the payload prop', () => {
  it('reads history through, so a prepended page moves the screen', () => {
    const source = sourceRef({ messages: [payloadMessage({ id: 'b' })] })
    const chat = chatWith(source)

    source.value = {
      ...source.value,
      messages: [payloadMessage({ id: 'a' }), payloadMessage({ id: 'b' })],
    }

    expect(chat.messages.value.map((message) => message.id)).toEqual(['a', 'b'])
  })

  it('retires a live turn once the server transcript carries its id', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Hi',
      })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
        messageId: 'stored-answer',
      })
    })

    const source = sourceRef()
    const chat = chatWith(source, { connection })

    await chat.send('Hello')

    // Still live: the server transcript has not caught up yet.
    expect(chat.messages.value).toHaveLength(2)
    expect(chat.messages.value[1].content).toBe('Hi')

    source.value = {
      ...source.value,
      messages: [payloadMessage({ id: 'stored-prompt', role: 'user' }), payloadMessage({ id: 'stored-answer' })],
    }

    expect(chat.messages.value.map((message) => message.id)).toEqual(['stored-prompt', 'stored-answer'])
  })

  it('drops every local trace when the server switches thread', async () => {
    const { connection } = transport()
    const source = sourceRef({ threadId: 'conv-1' })
    const chat = chatWith(source, { connection })

    await chat.send('Hello')

    source.value = { ...source.value, threadId: 'conv-2' }
    await nextTick()

    expect(chat.messages.value).toEqual([])
    expect(chat.threadId.value).toBe('conv-2')
  })
})

/* -------------------------------------------------------------------------- */
/* Resuming                                                                    */
/* -------------------------------------------------------------------------- */

describe('catching up', () => {
  it('catches up on mount from the offset the payload names', async () => {
    const attached: ChatAttachRequest[] = []
    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 3,
      }),
      {
        connection: attachable(attached),
      },
    )

    expect(chat.status.value).toBe('resuming')

    await flush()

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
    expect(chat.messages.value[0].content).toBe('Caught up')
  })

  it('reports each offset the thread is read to, and holds it on the session', async () => {
    const sessions: (ChatSession | null)[] = []
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), {
      connection: attachable([]),
      onSessionChange: (session) => sessions.push(session),
    })

    await flush()

    expect(sessions).toEqual([{ threadId: 'conv-1', offset: 4 }])
    expect(chat.session.value).toEqual({ threadId: 'conv-1', offset: 4 })
  })

  it('follows the thread on mount through the default transport', async () => {
    const fetch = vi.fn((_url: string, init: RequestInit) => {
      return new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(init.signal?.reason))
      })
    })

    vi.stubGlobal('fetch', fetch)

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        streamEndpoint: '/chat/:thread/stream',
        offset: 3,
      }),
    )

    await flush()

    expect(chat.status.value).toBe('resuming')
    expect(fetch).toHaveBeenCalledOnce()
    expect(String(fetch.mock.calls[0][0])).toContain('/chat/conv-1/stream')
  })

  // A connection that defines no `attach` cannot read a thread it did not post to, so the composer is enabled.
  it('settles to ready when the transport cannot catch up, rather than opening a read it cannot feed', async () => {
    const requests: ChatConnectionRequest[] = []
    const connection: ChatConnection = async (request) => {
      requests.push(request)
    }

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 3,
      }),
      {
        connection,
      },
    )

    await flush()

    expect(requests).toEqual([])
    expect(chat.messages.value).toEqual([])
    expect(chat.status.value).toBe('ready')

    // Asking again is a no-op with no notification, rather than a round trip through `resuming`.
    const seen: string[] = []
    const stop = chat.store.subscribe(() => seen.push(chat.store.getState().status))

    await chat.attach()
    stop()

    expect(seen).toEqual([])
  })
})

describe('syncing', () => {
  it('reads the thread again from where it caught up to, once the last read has ended', async () => {
    const attached: ChatAttachRequest[] = []
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: attachable(attached) })

    await flush()
    await chat.sync()

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([
      ['conv-1', 3],
      ['conv-1', 4],
    ])
  })
})

/* -------------------------------------------------------------------------- */
/* Scope                                                                       */
/* -------------------------------------------------------------------------- */

describe('the scope', () => {
  it('stops the run and stops listening when the scope is disposed', async () => {
    const { connection, requests, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const scope = effectScope()
    const chat = scope.run(() => useChat(sourceRef(), { connection })) as UseChatReturn

    void chat.send('Hello')
    await nextTick()

    expect(chat.streaming.value).toBe(true)
    expect(requests[0].signal.aborted).toBe(false)

    scope.stop()

    // `onScopeDispose` aborts the request rather than leaving it reading forever.
    expect(requests[0].signal.aborted).toBe(true)

    held.resolve()
  })
})

describe('the state it forwards', () => {
  it('moves the transcript with each token, without touching the fields that stay put', async () => {
    const { connection, next } = transport()
    const held = deferred()
    let emit: (event: AgUiEvent) => void = () => {}

    next(async (send) => {
      emit = send
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const latest = (): string => chat.messages.value[chat.messages.value.length - 1].content
    const running = chat.send('Hello')

    await nextTick()
    emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
    emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'assistant-1', delta: 'Hi' })
    await nextTick()

    expect(latest()).toBe('Hi')

    const statuses: string[] = []
    const stop = watch(chat.status, (status) => statuses.push(status), { flush: 'sync' })

    emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'assistant-1', delta: ' there' })
    await nextTick()

    expect(latest()).toBe('Hi there')
    expect(statuses).toEqual([])

    stop()
    held.resolve()
    await running
  })

  it('forwards each queued message with the id of its user message', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await nextTick()
    void chat.send('Second')

    expect(chat.queued.value).toEqual([
      { id: chat.messages.value[chat.messages.value.length - 1].id, content: 'Second' },
    ])

    held.resolve()
    await running
  })

  it('reads a queue ref each time a message arrives busy', async () => {
    const { connection, next } = transport()
    const held = deferred()
    const queue = ref<ChatQueue>('drop')

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection, queue })
    const running = chat.send('First')

    await nextTick()

    expect(await chat.send('Second')).toBe(false)

    queue.value = 'queue'

    expect(await chat.send('Third')).toBe(true)
    expect(chat.queued.value.map((entry) => entry.content)).toEqual(['Third'])

    held.resolve()
    await running
  })
})

describe('the errors it exports', () => {
  it('rejoins the thread for a custom connection that refuses with the exported busy error', async () => {
    const followed: ChatAttachRequest[] = []

    const connection: ChatConnection = async () => {
      throw new ChatThreadBusyError('conv-1', 'run-other', 3)
    }

    connection.attach = (request) => {
      followed.push(request)

      return new Promise((resolve) => request.signal.addEventListener('abort', () => resolve(), { once: true }))
    }

    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), { connection })

    expect(await chat.send('Mine')).toBe(true)
    expect(chat.status.value).toBe('streaming')
    expect(chat.error.value).toBeNull()
    expect(chat.queued.value.map((entry) => entry.content)).toEqual(['Mine'])
    expect(followed.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
  })

  it('exports every error a connection reports', () => {
    expect(new ChatStreamStalledError(3)).toBeInstanceOf(Error)
    expect(new ChatRequestError('Refused.', 422)).toBeInstanceOf(Error)
  })
})
