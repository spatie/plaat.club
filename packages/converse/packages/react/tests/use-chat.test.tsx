/**
 * What the React adapter adds, and nothing else.
 *
 * The store's own rules live in `@laravel/converse`'s `store.test.ts`, against the bare store.
 * What is left here is the part React is responsible for: the `useSyncExternalStore` wiring,
 * a payload read during render so a replaced prop is never a render behind, the effect that
 * tells the store a new thread arrived, and unmounting mid-run.
 */

import type {
  AgUiEvent,
  AgUiMessage,
  ChatAttachRequest,
  ChatMessage,
  ChatConnection,
  ChatConnectionRequest,
  ChatSession,
  ChatSource,
} from '@laravel/converse'
import { act, cleanup, render, screen } from '@testing-library/react'
import { StrictMode, Suspense, memo, startTransition, useLayoutEffect, useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useChat } from '../src/hooks/use-chat'
import { ChatRequestError, ChatStreamStalledError, ChatThreadBusyError } from '../src/index'

type Script = (emit: (event: AgUiEvent) => void, request: ChatConnectionRequest) => void | Promise<void>

function transport() {
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

  const connection: ChatConnection = async (request) => {
    requests.push(request)

    await (scripts.shift() ?? answer)(request.onEvent, request)
  }

  return {
    connection,
    requests,
    next: (script: Script) => scripts.push(script),
  }
}

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

    // A tick before the first frame, the way a request is: the effect returns while the catch-up is
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

/** Let a catch-up that is already in flight finish. */
function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

/** Renders the ids on screen and hands the test a way to replace the payload. */
function Harness({ initial, connection }: { initial: ChatSource; connection: ChatConnection }) {
  const [source, setSource] = useState(initial)
  const chat = useChat(source, { connection })

  setPayload = setSource
  latest = chat

  return (
    <div>
      <span data-testid="ids">{chat.messages.map((message) => message.id).join(',')}</span>
      <span data-testid="content">{chat.messages.map((message) => message.content).join('|')}</span>
      <span data-testid="thread">{String(chat.threadId)}</span>
      <span data-testid="streaming">{String(chat.streaming)}</span>
    </div>
  )
}

/** `useChat` on a payload that may name an offset, rendering the status the composer would read. */
function ResumeHarness({
  source,
  connection,
  onSessionChange,
}: {
  source: ChatSource
  connection?: ChatConnection
  onSessionChange?: (session: ChatSession | null) => void
}) {
  const chat = useChat(source, { connection, onSessionChange })

  latest = chat

  return (
    <div>
      <span data-testid="status">{chat.status}</span>
      <span data-testid="content">{chat.messages.map((message) => message.content).join('|')}</span>
      <span data-testid="session">{String(chat.session?.offset ?? null)}</span>
    </div>
  )
}

/** A page whose next payload may wait on data, the way a navigation inside a transition does. */
type PendingPage = { source: ChatSource; headers: Record<string, string>; waiting: boolean }

const neverLoads = new Promise<never>(() => {})

/** Suspends for as long as the page it belongs to is still loading. */
function Loading({ waiting }: { waiting: boolean }) {
  if (waiting) {
    throw neverLoads
  }

  return null
}

function TransitionHarness({ initial }: { initial: PendingPage }) {
  const [page, setPage] = useState(initial)
  const chat = useChat(page.source, { headers: page.headers })

  setPendingPage = setPage
  latest = chat

  return (
    <Suspense fallback="loading">
      <span data-testid="ids">{chat.messages.map((message) => message.id).join(',')}</span>
      <Loading waiting={page.waiting} />
    </Suspense>
  )
}

/** A page whose child sends from its own layout effect, as soon as the page it was given commits. */
type SendingPage = { source: ChatSource; headers: Record<string, string>; sendOnCommit: boolean }

function SendOnCommit({ send, sendOnCommit }: { send: (message: string) => Promise<boolean>; sendOnCommit: boolean }) {
  useLayoutEffect(() => {
    if (sendOnCommit) {
      void send('Hello').catch(() => {})
    }
  }, [send, sendOnCommit])

  return null
}

function SendingHarness({ initial }: { initial: SendingPage }) {
  const [page, setPage] = useState(initial)
  const chat = useChat(page.source, { headers: page.headers })

  setSendingPage = setPage

  return <SendOnCommit send={chat.send} sendOnCommit={page.sendOnCommit} />
}

/** Renders one message, skipping any render that hands it the same one again. */
const MessageView = memo(function MessageView({ message }: { message: ChatMessage }) {
  return <span data-testid="memoized">{message.content}</span>
})

function MemoHarness({ connection }: { connection: ChatConnection }) {
  const chat = useChat({ endpoint: '/chat' }, { connection })
  const answer = chat.messages[chat.messages.length - 1]

  latest = chat

  return answer?.role === 'assistant' ? <MessageView message={answer} /> : null
}

let setPendingPage: (page: PendingPage) => void
let setSendingPage: (page: SendingPage) => void
let setPayload: (source: ChatSource) => void
let latest: ReturnType<typeof useChat>

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('the payload prop', () => {
  it('reads history through, so a prepended page moves the screen', () => {
    const { connection } = transport()

    render(
      <Harness
        initial={{
          endpoint: '/chat',
          messages: [payloadMessage({ id: 'b' })],
        }}
        connection={connection}
      />,
    )

    expect(screen.getByTestId('ids').textContent).toBe('b')

    // No store notification: the prop alone has to move what is rendered.
    act(() =>
      setPayload({
        endpoint: '/chat',
        messages: [payloadMessage({ id: 'a' }), payloadMessage({ id: 'b' })],
      }),
    )

    expect(screen.getByTestId('ids').textContent).toBe('a,b')
  })

  it('tells the store when the payload names a different thread', async () => {
    const { connection } = transport()

    render(<Harness initial={{ endpoint: '/chat', threadId: 'conv-1' }} connection={connection} />)

    await act(async () => {
      await latest.send('Hello')
    })

    expect(screen.getByTestId('ids').textContent).not.toBe('')

    act(() => setPayload({ endpoint: '/chat', threadId: 'conv-2' }))

    expect(screen.getByTestId('ids').textContent).toBe('')
    expect(screen.getByTestId('thread').textContent).toBe('conv-2')
  })
})

describe('subscribing', () => {
  it('re-renders as tokens land, not only when the run ends', async () => {
    const { connection, next } = transport()
    const held = deferred()
    let emit: (event: AgUiEvent) => void = () => {}

    next(async (send) => {
      emit = send
      send({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })

      await held.promise
    })

    render(<Harness initial={{ endpoint: '/chat' }} connection={connection} />)

    await act(async () => {
      void latest.send('Hello')
    })

    expect(screen.getByTestId('streaming').textContent).toBe('true')

    await act(async () => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Hel',
      })
    })

    expect(screen.getByTestId('content').textContent).toContain('Hel')

    // The store mutates the streaming message in place, so this only holds
    // because each snapshot carries a fresh copy of it...
    await act(async () => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'lo',
      })
    })

    expect(screen.getByTestId('content').textContent).toContain('Hello')

    held.resolve()
  })

  it('hands a memoized child each delta of the streaming message', async () => {
    const { connection, next } = transport()
    const held = deferred()
    let emit: (event: AgUiEvent) => void = () => {}

    next(async (send) => {
      emit = send
      send({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      send({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm1', delta: 'Hel' })

      await held.promise
    })

    render(<MemoHarness connection={connection} />)

    await act(async () => {
      void latest.send('Hello')
    })

    expect(screen.getByTestId('memoized').textContent).toBe('Hel')

    await act(async () => {
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm1', delta: 'lo' })
    })

    expect(screen.getByTestId('memoized').textContent).toBe('Hello')

    held.resolve()
  })

  it('gives each message an id the server accepts, whatever shape the React id takes', async () => {
    const { connection } = transport()

    render(<Harness initial={{ endpoint: '/chat' }} connection={connection} />)

    await act(async () => {
      await latest.send('Hello')
    })

    expect(latest.messages.map((message) => message.id).filter((id) => !/^[A-Za-z0-9_-]+$/.test(id))).toEqual([])
  })

  it('survives StrictMode, which mounts and subscribes twice', async () => {
    const { connection } = transport()

    render(
      <StrictMode>
        <Harness initial={{ endpoint: '/chat' }} connection={connection} />
      </StrictMode>,
    )

    await act(async () => {
      await latest.send('Hello')
    })

    // One answer, not two: the second subscription must not double the turn.
    expect(screen.getByTestId('content').textContent).toBe('Hello|Hi')
  })
})

describe('a transition that has not committed', () => {
  it('sends to the thread on screen, with its headers, not the one still loading', async () => {
    const fetch = vi.fn(async (_url: string, _init: RequestInit) => new Response('', { status: 500 }))

    vi.stubGlobal('fetch', fetch)

    render(
      <TransitionHarness
        initial={{
          source: { endpoint: '/shown', messages: [payloadMessage({ id: 'shown' })] },
          headers: { 'X-Page': 'shown' },
          waiting: false,
        }}
      />,
    )

    await act(async () =>
      startTransition(() =>
        setPendingPage({
          source: { endpoint: '/loading', messages: [payloadMessage({ id: 'loading' })] },
          headers: { 'X-Page': 'loading' },
          waiting: true,
        }),
      ),
    )

    expect(screen.getByTestId('ids').textContent).toBe('shown')

    await act(async () => {
      await latest.send('Hello').catch(() => {})
    })

    expect(fetch).toHaveBeenCalledOnce()
    expect(String(fetch.mock.calls[0][0])).toBe('/shown')
    expect(new Headers(fetch.mock.calls[0][1].headers).get('X-Page')).toBe('shown')
  })

  it('sends to the new thread once it commits', async () => {
    const fetch = vi.fn(async (_url: string, _init: RequestInit) => new Response('', { status: 500 }))

    vi.stubGlobal('fetch', fetch)

    render(<TransitionHarness initial={{ source: { endpoint: '/shown' }, headers: {}, waiting: false }} />)

    act(() => setPendingPage({ source: { endpoint: '/next' }, headers: {}, waiting: false }))

    await act(async () => {
      await latest.send('Hello').catch(() => {})
    })

    expect(String(fetch.mock.calls[0][0])).toBe('/next')
  })
})

describe('a child layout effect', () => {
  it('sends to the page that just committed, with its headers', async () => {
    const fetch = vi.fn(async (_url: string, _init: RequestInit) => new Response('', { status: 500 }))

    vi.stubGlobal('fetch', fetch)

    render(
      <SendingHarness
        initial={{ source: { endpoint: '/before' }, headers: { 'X-Page': 'before' }, sendOnCommit: false }}
      />,
    )

    await act(async () =>
      setSendingPage({ source: { endpoint: '/after' }, headers: { 'X-Page': 'after' }, sendOnCommit: true }),
    )

    expect(fetch).toHaveBeenCalledOnce()
    expect(String(fetch.mock.calls[0][0])).toBe('/after')
    expect(new Headers(fetch.mock.calls[0][1].headers).get('X-Page')).toBe('after')
  })
})

describe('catching up', () => {
  it('catches up on mount from the offset the payload names', async () => {
    const attached: ChatAttachRequest[] = []

    render(
      <ResumeHarness
        source={{
          endpoint: '/chat',
          threadId: 'conv-1',
          offset: 3,
        }}
        connection={attachable(attached)}
      />,
    )

    expect(screen.getByTestId('status').textContent).toBe('resuming')

    await act(async () => {
      await flush()
    })

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
    expect(screen.getByTestId('content').textContent).toBe('Caught up')
  })

  it('reports each offset the thread is read to, and holds it on the session', async () => {
    const sessions: (ChatSession | null)[] = []

    render(
      <ResumeHarness
        source={{ endpoint: '/chat', threadId: 'conv-1', offset: 3 }}
        connection={attachable([])}
        onSessionChange={(session) => sessions.push(session)}
      />,
    )

    await act(async () => {
      await flush()
    })

    expect(sessions).toEqual([{ threadId: 'conv-1', offset: 4 }])
    expect(screen.getByTestId('session').textContent).toBe('4')
  })

  it('follows the thread on mount through the default transport', async () => {
    const fetch = vi.fn((_url: string, init: RequestInit) => {
      return new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(init.signal?.reason))
      })
    })

    vi.stubGlobal('fetch', fetch)

    render(
      <ResumeHarness
        source={{
          endpoint: '/chat',
          threadId: 'conv-1',
          streamEndpoint: '/chat/:thread/stream',
          offset: 3,
        }}
      />,
    )

    await act(async () => {
      await flush()
    })

    expect(screen.getByTestId('status').textContent).toBe('resuming')
    expect(fetch).toHaveBeenCalledOnce()
    expect(String(fetch.mock.calls[0][0])).toContain('/chat/conv-1/stream')
  })

  // A connection that defines no `attach` cannot read a thread it did not post to, so the composer is enabled.
  it('settles to ready when the transport cannot catch up, rather than opening a read it cannot feed', async () => {
    const requests: ChatConnectionRequest[] = []
    const connection: ChatConnection = async (request) => {
      requests.push(request)
    }

    render(
      <ResumeHarness
        source={{
          endpoint: '/chat',
          threadId: 'conv-1',
          offset: 3,
        }}
        connection={connection}
      />,
    )

    await act(async () => {
      await flush()
    })

    expect(requests).toEqual([])
    expect(screen.getByTestId('content').textContent).toBe('')
    expect(screen.getByTestId('status').textContent).toBe('ready')

    /*
     * And it converges there by refusing, not by luck. Asking again with nothing to feed the read must be
     * a no-op — no notification at all. A store that took the run instead announces `resuming`, disabling
     * the composer and the approvals while it opens a catch-up, appends a turn, discards it and lands back
     * on `ready`; it arrives in the same place, so only the announcements in between say which happened,
     * which is why this converged-by-accident bug survived every other test in this file.
     */
    const seen: string[] = []
    const stop = latest.store.subscribe(() => seen.push(latest.store.getState().status))

    await act(async () => {
      await latest.attach()
    })

    stop()

    expect(seen).toEqual([])
  })
})

describe('syncing', () => {
  it('reads the thread again from where it caught up to, once the last read has ended', async () => {
    const attached: ChatAttachRequest[] = []

    render(
      <ResumeHarness source={{ endpoint: '/chat', threadId: 'conv-1', offset: 3 }} connection={attachable(attached)} />,
    )

    await act(async () => {
      await flush()
    })

    await act(async () => {
      await latest.sync()
    })

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([
      ['conv-1', 3],
      ['conv-1', 4],
    ])
  })
})

describe('unmounting', () => {
  it('stops a run in flight', async () => {
    const { connection, requests, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const view = render(<Harness initial={{ endpoint: '/chat' }} connection={connection} />)

    await act(async () => {
      void latest.send('Hello')
    })

    expect(requests[0].signal.aborted).toBe(false)

    view.unmount()

    expect(requests[0].signal.aborted).toBe(true)

    held.resolve()
  })
})

describe('the state it forwards', () => {
  it('forwards each queued message with the id of its user message', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    render(<Harness initial={{ endpoint: '/chat' }} connection={connection} />)

    let running: Promise<boolean> = Promise.resolve(true)

    await act(async () => {
      running = latest.send('First')
    })

    await act(async () => {
      void latest.send('Second')
    })

    expect(latest.queued).toEqual([{ id: latest.messages[latest.messages.length - 1].id, content: 'Second' }])

    await act(async () => {
      held.resolve()
      await running
    })
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

    render(<ResumeHarness source={{ endpoint: '/chat', threadId: 'conv-1' }} connection={connection} />)

    await act(async () => {
      expect(await latest.send('Mine')).toBe(true)
    })

    expect(screen.getByTestId('status').textContent).toBe('streaming')
    expect(latest.error).toBeNull()
    expect(latest.queued.map((entry) => entry.content)).toEqual(['Mine'])
    expect(followed.map((request) => [request.threadId, request.offset])).toEqual([['conv-1', 3]])
  })

  it('exports every error a connection reports', () => {
    expect(new ChatStreamStalledError(3)).toBeInstanceOf(Error)
    expect(new ChatRequestError('Refused.', 422)).toBeInstanceOf(Error)
  })
})
