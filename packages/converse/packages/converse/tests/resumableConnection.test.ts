/**
 * The transport that sends a turn and follows the thread's durable stream: where a read starts from, what it
 * does with the sentinel the server closes a read with, and the request that stops a run for good.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ChatRequestError, ChatStreamStalledError, ChatThreadBusyError } from '../src/errors'
import { StreamResponseError } from '../src/eventStream'
import {
  createResumableConnection,
  resumableConnectionConfig,
  type ResumableConnectionConfig,
} from '../src/resumableConnection'
import { resumableReaderFor, type JsonEventStreamRequest } from '../src/streamReader'
import type { AgUiEvent, ChatSession, ChatSource, ChatStreamPosition } from '../src/types'
import {
  acceptedResponse,
  csrf,
  emitAt,
  endRead,
  endReadWith,
  FakeFailure,
  frame,
  isFailure,
  request,
  silentRead,
  upToDate,
} from './streamDoubles'

/** One read's script, handed the reader's `onEvent`. */
type ReadScript = (onEvent: JsonEventStreamRequest['onEvent']) => void | Promise<void>

/** The connection under test, against the package's routes and whatever the test overrides. */
function connect(overrides: Partial<ResumableConnectionConfig> = {}) {
  return createResumableConnection({
    url: '/chat/agent',
    streamUrl: (threadId) => `/chat/agent/${threadId}/stream`,
    csrf,
    read: vi.fn(),
    ...overrides,
  })
}

/**
 * A reader that answers each read with the next script, and stops following once they run out, the way a page
 * going away does.
 */
function scripted(controller: AbortController, ...scripts: ReadScript[]) {
  return vi.fn().mockImplementation(async ({ signal, onEvent }: JsonEventStreamRequest) => {
    const script = scripts.shift()

    if (script === undefined) {
      controller.abort()

      throw signal.reason
    }

    await script(onEvent)
  })
}

/** Follow the thread through the connection, recording everything it reports. */
function follow(connection: ReturnType<typeof connect>, controller: AbortController, offset = 0) {
  const events: AgUiEvent[] = []
  const positions: ChatStreamPosition[] = []

  const running = connection.attach?.({
    threadId: 'conv-1',
    offset,
    signal: controller.signal,
    onEvent: (event) => events.push(event),
    onUpToDate: (position) => positions.push(position),
  })

  // Settled either way, so a test reads what it ended with without an unhandled rejection in between.
  const ended = Promise.resolve(running).then(
    () => null,
    (error: unknown) => error,
  )

  return { events, positions, ended }
}

/** The stream URLs the reader was asked for, in order. */
function urls(read: ReturnType<typeof vi.fn>): string[] {
  return read.mock.calls.map((call) => (call[0] as { url: string }).url)
}

const content = (delta: string): AgUiEvent => ({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta })

function busyResponse(body: Record<string, unknown>): Response {
  return new Response(JSON.stringify(body), { status: 409, headers: { 'Content-Type': 'application/json' } })
}

beforeEach(() => {
  // No jitter, so a follow reconnects on the next tick rather than at a random point in the next quarter second.
  vi.spyOn(Math, 'random').mockReturnValue(0)
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('sending a turn', () => {
  it('posts the turn and answers with the run the server accepted it as, reading nothing', async () => {
    const fetch = vi.fn().mockResolvedValue(acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 41 }))
    const read = vi.fn()

    vi.stubGlobal('fetch', fetch)

    const accepted = await connect({ read })(request())

    expect(accepted).toEqual({ threadId: 'conv-1', runId: 'run-1', offset: 41 })
    expect(fetch.mock.calls[0][0]).toBe('/chat/agent')
    expect(read).not.toHaveBeenCalled()
  })

  it('signs the turn it sends with the CSRF headers it was handed', async () => {
    const fetch = vi.fn().mockResolvedValue(acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 0 }))

    vi.stubGlobal('fetch', fetch)

    const headers = vi.fn().mockReturnValue({ 'X-CSRF-TOKEN': 'from-the-meta-tag' })

    await connect({ csrf: headers, csrfToken: 'token-1' })(request())

    expect(headers).toHaveBeenCalledWith({ csrfToken: 'token-1' })
    expect(fetch.mock.calls[0][1].headers).toMatchObject({ 'X-CSRF-TOKEN': 'from-the-meta-tag' })
  })

  it.each([
    ['no run', { threadId: 'conv-1', offset: 0 }, 'The chat endpoint did not return an accepted turn.'],
    [
      'no offset',
      { threadId: 'conv-1', runId: 'run-1' },
      'The chat endpoint returned an accepted turn without an offset.',
    ],
  ])('refuses an accepted turn naming %s', async (_case, body, message) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status: 202 })))

    await expect(connect()(request())).rejects.toThrow(message)
  })

  it('refuses a turn the thread is too busy for, naming the run that holds it and where its events begin', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        busyResponse({
          message: 'This chat thread is already answering.',
          threadId: 'conv-1',
          runId: 'run-live',
          offset: 37,
        }),
      ),
    )

    const running = connect()(request())

    // Not delivered: the store decides what becomes of the turn, and follows the thread itself.
    await expect(running).rejects.toBeInstanceOf(ChatThreadBusyError)
    await expect(running).rejects.toMatchObject({ threadId: 'conv-1', runId: 'run-live', offset: 37 })
  })

  it.each([
    ['a duplicate', { message: 'This message was already sent.', threadId: 'conv-1', runId: null, offset: 44 }],
    ['an approval mismatch', { message: 'The approvals do not match.', code: 'approval_mismatch', approvals: [] }],
    [
      'a busy thread with no offset',
      { message: 'This chat thread is already answering.', threadId: 'conv-1', runId: 'run-live' },
    ],
  ])('keeps %s a request error, since it names no run to wait for', async (_case, body) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(busyResponse(body)))

    const running = connect()(request())

    await expect(running).rejects.toBeInstanceOf(ChatRequestError)
    await expect(running).rejects.toMatchObject({ status: 409, message: body.message })
  })

  it('keeps a 409 whose body is not JSON a request error', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('Conflict', { status: 409 })))

    await expect(connect()(request())).rejects.toBeInstanceOf(ChatRequestError)
  })

  // The raw body rides along, so a refusal still has whatever the server put in it to read out.
  it('reports a refused send as a request error carrying the body it was refused with', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ message: 'That is too long.' }), {
          status: 422,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    )

    await expect(connect()(request())).rejects.toMatchObject({
      name: 'ChatRequestError',
      status: 422,
      message: 'That is too long.',
      body: '{"message":"That is too long."}',
    })
  })
})

describe('following a thread', () => {
  it('catches up with a bounded read from the offset, then follows from where it caught up to', async () => {
    const controller = new AbortController()
    const offsets: ChatSession[] = []
    const read = scripted(
      controller,
      (onEvent) => {
        emitAt(onEvent, 5, content('Hi'))
        emitAt(onEvent, 6, content(' there'))
        endRead(onEvent, 6, true)
      },
      (onEvent) => {
        emitAt(onEvent, 7, { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
        endRead(onEvent, 7)
      },
    )

    const { events, positions, ended } = follow(
      connect({ read, onOffset: (session) => offsets.push(session) }),
      controller,
      4,
    )

    // A read that finds no run live is the last one.
    expect(await ended).toBeNull()
    expect(urls(read)).toEqual([
      '/chat/agent/conv-1/stream?offset=4&follow=0',
      '/chat/agent/conv-1/stream?offset=6&follow=1',
    ])
    expect(events.map((event) => event.type)).toEqual(['TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_CONTENT', 'RUN_FINISHED'])
    expect(positions).toEqual([
      { offset: 6, live: true },
      { offset: 7, live: false },
    ])
    expect(offsets).toEqual([
      { threadId: 'conv-1', offset: 5 },
      { threadId: 'conv-1', offset: 6 },
      { threadId: 'conv-1', offset: 7 },
    ])
  })

  it('stops reading once a read finds no run live, however long it is left', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => endRead(onEvent, 3),
      (onEvent) => endRead(onEvent, 3),
    )

    const { positions, ended } = follow(connect({ read }), controller, 3)

    await vi.advanceTimersByTimeAsync(60_000)

    expect(await ended).toBeNull()
    expect(read).toHaveBeenCalledTimes(1)
    expect(positions).toEqual([{ offset: 3, live: false }])
  })

  it('reads again at once where a send landed while the read that found no run was open', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 3 })),
    )

    const controller = new AbortController()
    let connection: ReturnType<typeof connect> | null = null
    const read = scripted(
      controller,
      async (onEvent) => {
        await connection?.(request())
        endRead(onEvent, 3)
      },
      (onEvent) => endRead(onEvent, 4, true),
    )

    connection = connect({ read })

    const { positions, ended } = follow(connection, controller, 3)

    expect(await ended).toBeInstanceOf(DOMException)
    expect(positions).toEqual([
      { offset: 3, live: false },
      { offset: 4, live: true },
    ])
  })

  it('moves on to where the server says it caught up to, past a gap the stream no longer holds', async () => {
    const controller = new AbortController()
    const offsets: ChatSession[] = []
    const read = scripted(controller, (onEvent) => endRead(onEvent, 9, true))

    const { positions, ended } = follow(connect({ read, onOffset: (session) => offsets.push(session) }), controller, 4)

    await ended

    expect(urls(read)[1]).toBe('/chat/agent/conv-1/stream?offset=9&follow=1')
    expect(positions).toEqual([{ offset: 9, live: true }])
    expect(offsets).toEqual([{ threadId: 'conv-1', offset: 9 }])
  })

  it('never moves back to an offset behind the one it read to', async () => {
    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => {
      emitAt(onEvent, 5, content('Hi'))
      endRead(onEvent, 2)
    })

    const { positions, ended } = follow(connect({ read }), controller, 4)

    await ended

    expect(positions).toEqual([{ offset: 5, live: false }])
  })

  it('drops the frames a reconnect overlaps with the ones it already handed over', async () => {
    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => {
      emitAt(onEvent, 4, content('Old'))
      emitAt(onEvent, 5, content('New'))
      emitAt(onEvent, 5, content('New'))
      endRead(onEvent, 5)
    })

    const { events, ended } = follow(connect({ read }), controller, 4)

    await ended

    expect(events).toEqual([content('New')])
  })

  // A pruned offset is answered with a snapshot, and the interrupts that close it have no position of their own.
  it('hands over the frame that closes a snapshot under the snapshot’s own position', async () => {
    const controller = new AbortController()
    const snapshot: AgUiEvent = { type: 'MESSAGES_SNAPSHOT', messages: [] }
    const closing: AgUiEvent = { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'snapshot' }
    const read = scripted(controller, (onEvent) => {
      emitAt(onEvent, 9, snapshot)
      emitAt(onEvent, 9, closing)
      emitAt(onEvent, 9, closing)
      endRead(onEvent, 9)
    })

    const { events, ended } = follow(connect({ read }), controller, 2)

    await ended

    expect(events).toEqual([snapshot, closing])
  })

  // A thread pruned and started again numbers from 1, below the offset a stale browser still holds.
  it('restarts from a snapshot behind the offset it followed from, then hands over what follows it', async () => {
    const controller = new AbortController()
    const snapshot: AgUiEvent = { type: 'MESSAGES_SNAPSHOT', messages: [] }
    const read = scripted(controller, (onEvent) => {
      emitAt(onEvent, 3, snapshot)
      emitAt(onEvent, 4, content('New'))
      endRead(onEvent, 4)
    })

    const { events, ended } = follow(connect({ read }), controller, 12)

    await ended

    expect(events).toEqual([snapshot, content('New')])
  })

  it('reconnects a follow of a live run a little later, so tabs whose reads ended together spread out', async () => {
    vi.useFakeTimers()
    vi.mocked(Math.random).mockReturnValue(0.5)

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => endRead(onEvent, 0, true),
      (onEvent) => endRead(onEvent, 0, true),
    )

    const { ended } = follow(connect({ read }), controller, 0)

    // The catch-up goes straight on to following.
    await vi.advanceTimersByTimeAsync(0)

    expect(read).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(124)

    expect(read).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(1)

    expect(read).toHaveBeenCalledTimes(3)

    await ended
  })

  it('reads a polled thread again after the interval its sentinel asks for, never following it, until nothing is live', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => {
        emitAt(onEvent, 1, content('Hel'))
        endReadWith(onEvent, { ...upToDate(1, true), poll: 2000 })
      },
      (onEvent) => endReadWith(onEvent, { ...upToDate(1, true), poll: 2000 }),
      (onEvent) => {
        emitAt(onEvent, 2, content('lo'))
        endReadWith(onEvent, { ...upToDate(2), poll: 2000 })
      },
    )

    const { events, positions, ended } = follow(connect({ read }), controller, 0)

    await vi.advanceTimersByTimeAsync(1999)

    expect(read).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1)

    expect(read).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(2000)

    expect(await ended).toBeNull()
    expect(urls(read)).toEqual([
      '/chat/agent/conv-1/stream?offset=0&follow=0',
      '/chat/agent/conv-1/stream?offset=1&follow=0',
      '/chat/agent/conv-1/stream?offset=1&follow=0',
    ])
    expect(events).toEqual([content('Hel'), content('lo')])
    expect(positions).toEqual([
      { offset: 1, live: true },
      { offset: 1, live: true },
      { offset: 2, live: false },
    ])
  })

  it('waits the interval each sentinel asks for between reads of a polled thread', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => endReadWith(onEvent, { ...upToDate(0, true), poll: 500 }),
      (onEvent) => endReadWith(onEvent, { ...upToDate(0, true), poll: 1500 }),
      (onEvent) => endReadWith(onEvent, { ...upToDate(0), poll: 1500 }),
    )

    const { ended } = follow(connect({ read }), controller, 0)

    await vi.advanceTimersByTimeAsync(499)

    expect(read).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1)

    expect(read).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(1499)

    expect(read).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(1)

    expect(await ended).toBeNull()
    expect(read).toHaveBeenCalledTimes(3)
  })

  it.each([0, -1, Number.POSITIVE_INFINITY, '2000', true])(
    'follows a thread whose sentinel asks to poll with %s',
    async (poll) => {
      const controller = new AbortController()
      const read = scripted(
        controller,
        (onEvent) => endReadWith(onEvent, { ...upToDate(0, true), poll }),
        (onEvent) => endReadWith(onEvent, upToDate(0)),
      )

      expect(await follow(connect({ read }), controller, 0).ended).toBeNull()
      expect(urls(read)).toEqual([
        '/chat/agent/conv-1/stream?offset=0&follow=0',
        '/chat/agent/conv-1/stream?offset=0&follow=1',
      ])
    },
  )

  it('stops waiting to poll again once the follow is aborted', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => endReadWith(onEvent, { ...upToDate(0, true), poll: 2000 }))

    const { ended } = follow(connect({ read }), controller, 0)

    await vi.advanceTimersByTimeAsync(0)
    controller.abort()

    expect(await ended).toBeInstanceOf(DOMException)

    await vi.advanceTimersByTimeAsync(2000)

    expect(read).toHaveBeenCalledTimes(1)
  })

  it('refuses a frame that carries no SSE id', async () => {
    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => onEvent(content('Hi'), frame(null, null, content('Hi'))))

    const { ended } = follow(connect({ read }), controller)

    expect(await ended).toEqual(new Error('A resumable chat stream frame is missing its SSE id.'))
  })

  it.each([
    ['a reason it does not know', { reason: 'finished', offset: 3, live: false }],
    ['no offset', { reason: 'up-to-date', live: false }],
    ['no live flag', { reason: 'up-to-date', offset: 3 }],
    ['no body at all', null],
  ])('refuses a sentinel carrying %s', async (_case, sentinel) => {
    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => endReadWith(onEvent, sentinel))

    const { ended } = follow(connect({ read }), controller)

    expect(await ended).toEqual(new Error('The chat stream sent an invalid converse-stream-end sentinel.'))
  })

  it('reads the offset out of a sentinel that carries it as a string', async () => {
    const controller = new AbortController()
    const sentinel = { reason: 'up-to-date', offset: '8', live: false }
    const read = scripted(controller, (onEvent) => endReadWith(onEvent, sentinel))

    const { positions, ended } = follow(connect({ read }), controller)

    await ended

    expect(positions).toEqual([{ offset: 8, live: false }])
  })

  it('stays bounded when a bounded read fails transiently, and asks again from where it got to', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => {
        emitAt(onEvent, 1, content('Hi'))

        throw new TypeError('Failed to fetch')
      },
      (onEvent) => endRead(onEvent, 1),
    )

    const { ended } = follow(connect({ read }), controller)

    await vi.advanceTimersByTimeAsync(60_000)
    await ended

    expect(urls(read).slice(0, 2)).toEqual([
      '/chat/agent/conv-1/stream?offset=0&follow=0',
      '/chat/agent/conv-1/stream?offset=1&follow=0',
    ])
  })

  it('takes a read that dropped after its sentinel as caught up', async () => {
    const controller = new AbortController()
    const read = scripted(controller, (onEvent) => {
      endRead(onEvent, 4)

      throw new TypeError('Failed to fetch')
    })

    const { positions, ended } = follow(connect({ read }), controller)

    expect(await ended).toBeNull()
    expect(positions).toEqual([{ offset: 4, live: false }])
    expect(read).toHaveBeenCalledTimes(1)
  })

  it('starts the budget over after a read that applied a frame before failing transiently', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      () => {
        throw new TypeError('Failed to fetch')
      },
      (onEvent) => {
        emitAt(onEvent, 1, content('a'))

        throw new TypeError('Failed to fetch')
      },
      () => {
        throw new TypeError('Failed to fetch')
      },
      (onEvent) => endRead(onEvent, 1),
    )

    const { events, ended } = follow(connect({ read, idleAttempts: 1 }), controller)

    await vi.advanceTimersByTimeAsync(60_000)

    expect(await ended).toBeNull()
    expect(events).toEqual([content('a')])
  })

  it('spends the budget on a transport that keeps failing, and reports what it failed with', async () => {
    vi.useFakeTimers()

    const failure = new FakeFailure(503, 'Service unavailable')
    const read = vi.fn().mockRejectedValue(failure)
    const { ended } = follow(connect({ read, idleAttempts: 2, isResponseFailure: isFailure }), new AbortController())

    await vi.advanceTimersByTimeAsync(60_000)

    const error = await ended

    expect(error).toBeInstanceOf(ChatStreamStalledError)
    expect((error as ChatStreamStalledError).attempts).toBe(3)
    expect((error as ChatStreamStalledError).reason).toBe(failure)
    expect(read).toHaveBeenCalledTimes(3)
  })

  it('spends the budget on reads that are cut off before their sentinel', async () => {
    vi.useFakeTimers()

    const read = vi.fn().mockResolvedValue(undefined)
    const { ended } = follow(connect({ read, idleAttempts: 1 }), new AbortController())

    await vi.advanceTimersByTimeAsync(60_000)

    const error = await ended

    expect(error).toBeInstanceOf(ChatStreamStalledError)
    expect((error as ChatStreamStalledError).reason).toBeNull()
  })

  it('never gives up on a stream that keeps progressing: one applied frame resets the budget', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = scripted(
      controller,
      (onEvent) => emitAt(onEvent, 1, content('a')),
      (onEvent) => emitAt(onEvent, 2, content('b')),
      (onEvent) => emitAt(onEvent, 3, content('c')),
      (onEvent) => endRead(onEvent, 3),
    )

    const { events, ended } = follow(connect({ read, idleAttempts: 0 }), controller)

    await vi.advanceTimersByTimeAsync(60_000)

    expect(await ended).toBeNull()
    expect(events).toHaveLength(3)
  })

  it('reconnects rather than hanging on a stream that opened and then went silent', async () => {
    vi.useFakeTimers()

    const signals: AbortSignal[] = []
    const { ended } = follow(
      connect({
        read: resumableReaderFor(silentRead({ 'X-Converse-Follow-Window-Milliseconds': '1000' }, signals)),
        idleAttempts: 1,
      }),
      new AbortController(),
    )

    await vi.advanceTimersByTimeAsync(60_000)

    // A silent socket is a transient failure the budget may spend, rather than a read that never returns.
    expect(await ended).toBeInstanceOf(ChatStreamStalledError)
    expect(signals).toHaveLength(2)
  })

  it('retries a refusal the failure predicate recognises', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = vi
      .fn()
      .mockRejectedValueOnce(new FakeFailure(503, 'Service unavailable'))
      .mockImplementationOnce(async ({ onEvent }: JsonEventStreamRequest) => {
        endRead(onEvent, 0)
        controller.abort()
      })

    const { positions, ended } = follow(connect({ read, isResponseFailure: isFailure }), controller)

    await vi.advanceTimersByTimeAsync(60_000)
    await ended

    expect(positions).toEqual([{ offset: 0, live: false }])
  })

  it('reports a refusal no reconnect recovers from as a request error', async () => {
    const read = vi.fn().mockRejectedValue(new FakeFailure(403, '{"message":"This action is unauthorized."}'))
    const { ended } = follow(connect({ read, isResponseFailure: isFailure }), new AbortController())

    expect(await ended).toMatchObject({
      name: 'ChatRequestError',
      status: 403,
      message: 'This action is unauthorized.',
    })
    expect(read).toHaveBeenCalledOnce()
  })

  it('passes on a failure that is no refusal at all', async () => {
    const failure = new Error('The reader broke.')
    const { ended } = follow(connect({ read: vi.fn().mockRejectedValue(failure) }), new AbortController())

    expect(await ended).toBe(failure)
  })
})

describe('a throw from the handler', () => {
  it('fails the read rather than retrying it', async () => {
    const failure = new TypeError("Cannot read properties of undefined (reading 'length')")
    const read = vi.fn().mockImplementation(async ({ onEvent }: JsonEventStreamRequest) => {
      emitAt(onEvent, 1, content('Hi'))
    })

    const running = connect({ read }).attach?.({
      threadId: 'conv-1',
      offset: 0,
      signal: new AbortController().signal,
      onEvent: () => {
        throw failure
      },
    })

    await expect(running).rejects.toBe(failure)
    expect(read).toHaveBeenCalledOnce()
  })

  it('leaves the offset on the last frame the handler took', async () => {
    const offsets: ChatSession[] = []
    const read = vi.fn().mockImplementation(async ({ onEvent }: JsonEventStreamRequest) => {
      emitAt(onEvent, 1, { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emitAt(onEvent, 2, content('Hi'))
    })

    const running = connect({ read, onOffset: (session) => offsets.push(session) }).attach?.({
      threadId: 'conv-1',
      offset: 0,
      signal: new AbortController().signal,
      onEvent: (event) => {
        if (event.type === 'TEXT_MESSAGE_CONTENT') {
          throw new Error('The handler failed.')
        }
      },
    })

    await expect(running).rejects.toThrow('The handler failed.')
    expect(offsets).toEqual([{ threadId: 'conv-1', offset: 1 }])
  })

  it('fails the read where the offset callback throws, rather than reconnecting after every frame', async () => {
    const failure = new TypeError("Cannot read properties of undefined (reading 'offset')")
    const read = vi.fn().mockImplementation(async ({ onEvent }: JsonEventStreamRequest) => {
      emitAt(onEvent, 1, content('Hi'))
    })

    const running = connect({
      read,
      onOffset: () => {
        throw failure
      },
    }).attach?.({
      threadId: 'conv-1',
      offset: 0,
      signal: new AbortController().signal,
      onEvent: () => {},
    })

    await expect(running).rejects.toBe(failure)
    expect(read).toHaveBeenCalledOnce()
  })
})

describe('waiting between reads', () => {
  it('lets go of the abort signal once the backoff has been waited out', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const added = vi.spyOn(controller.signal, 'addEventListener')
    const removed = vi.spyOn(controller.signal, 'removeEventListener')
    const read = vi
      .fn()
      .mockImplementationOnce(async () => {
        throw new TypeError('Failed to fetch')
      })
      .mockImplementationOnce(async () => {
        controller.abort()
      })

    const { ended } = follow(connect({ read }), controller)

    await vi.advanceTimersByTimeAsync(1000)
    await ended

    expect(read).toHaveBeenCalledTimes(2)
    expect(added).toHaveBeenCalledOnce()
    expect(removed).toHaveBeenCalledWith('abort', added.mock.calls[0][1])
  })

  it('stops waiting the moment the follow is aborted', async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'))
    const { ended } = follow(connect({ read }), controller)

    await vi.advanceTimersByTimeAsync(0)
    controller.abort()

    expect(await ended).toBeInstanceOf(DOMException)
    expect(read).toHaveBeenCalledOnce()
  })
})

/*
 * Stopping a run for good is a request of its own, to the route the package registers beside the stream route.
 */
describe('stopping a run', () => {
  function accepted(): Response {
    return new Response(JSON.stringify({ status: 'accepted' }), {
      status: 202,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  it('posts to the cancel route beside the stream route it reads', async () => {
    const fetch = vi.fn().mockResolvedValue(accepted())

    vi.stubGlobal('fetch', fetch)

    await connect({ csrfToken: 'token-1', credentials: 'include', headers: { 'X-Persona': 'terse' } }).cancel?.({
      threadId: 'conv 1',
      runId: 'run-1',
    })

    expect(fetch.mock.calls[0][0]).toBe('/chat/agent/conv%201/runs/run-1/cancel')
    expect(fetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-TOKEN': 'token-1', 'X-Persona': 'terse' },
    })
    // No signal: a page that is going away still wants the run it started to stop.
    expect(fetch.mock.calls[0][1].signal).toBeUndefined()
  })

  it('keeps an absolute stream route absolute, and drops the offset off it', async () => {
    const fetch = vi.fn().mockResolvedValue(accepted())

    vi.stubGlobal('fetch', fetch)

    await connect({
      streamUrl: () => 'https://chat.test/chat/agent/conv-1/stream?offset=4&follow=1',
    }).cancel?.({ threadId: 'conv-1', runId: 'run 1' })

    expect(fetch.mock.calls[0][0]).toBe('https://chat.test/chat/agent/conv-1/runs/run%201/cancel')
  })

  it('refuses to guess a cancel route for a stream route that is not this package’s', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(accepted()))

    await expect(
      connect({ streamUrl: (threadId) => `/stream/${threadId}` }).cancel?.({
        threadId: 'conv-1',
        runId: 'run-1',
      }),
    ).rejects.toThrow('The chat stream route is not one a cancel route can be derived from.')
  })

  it('reports a refused cancel as a request error, so the store is not told the run stopped', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ message: 'Chat thread [conv-1] was not found.' }), {
          status: 404,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    )

    await expect(connect().cancel?.({ threadId: 'conv-1', runId: 'run-1' })).rejects.toThrow(
      'Chat thread [conv-1] was not found.',
    )
  })

  // Against a server that holds a thread while a run is live: a durable stop releases the claim.
  it('delivers the next prompt once the run holding the thread was stopped', async () => {
    let live: string | null = null
    let minted = 0

    const posted: string[] = []

    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string, init: { body?: string }) => {
        if (url.endsWith('/cancel')) {
          live = null

          return accepted()
        }

        if (live !== null) {
          return busyResponse({
            message: 'This chat thread is already answering.',
            threadId: 'conv-1',
            runId: live,
            offset: 3,
          })
        }

        posted.push(String(JSON.parse(init.body ?? '{}').message))
        minted += 1
        live = `run-${minted}`

        return acceptedResponse({ threadId: 'conv-1', runId: live, offset: minted * 10 })
      }),
    )

    const connection = connect()

    await connection({ ...request(), body: { threadId: 'conv-1', message: 'First' } })
    await expect(connection({ ...request(), body: { threadId: 'conv-1', message: 'Second' } })).rejects.toMatchObject({
      runId: 'run-1',
    })

    await connection.cancel?.({ threadId: 'conv-1', runId: 'run-1' })

    expect(await connection({ ...request(), body: { threadId: 'conv-1', message: 'Second' } })).toEqual({
      threadId: 'conv-1',
      runId: 'run-2',
      offset: 20,
    })
    expect(posted).toEqual(['First', 'Second'])
  })
})

describe('a resumable connection built directly', () => {
  it("retries a read the package's own reader was refused with a 503, with no failure test passed", async () => {
    vi.useFakeTimers()

    const controller = new AbortController()
    const read = vi
      .fn()
      .mockRejectedValueOnce(new StreamResponseError(503, 'Service unavailable'))
      .mockImplementationOnce(async () => {
        controller.abort()
      })

    const { ended } = follow(connect({ read }), controller)

    await vi.advanceTimersByTimeAsync(30_000)
    await ended

    expect(read).toHaveBeenCalledTimes(2)
  })

  it('signs its reads with the csrf headers it was given', async () => {
    const controller = new AbortController()
    const read = scripted(controller)
    const signed = (): Record<string, string> => ({ 'X-Signed': 'yes' })

    await follow(connect({ csrf: signed, read }), controller).ended

    expect(read.mock.calls[0][0].csrf).toBe(signed)
  })
})

describe('the routes a resumable connection is built from', () => {
  function payload(overrides: Partial<ChatSource> = {}): ChatSource {
    return {
      endpoint: '/chat/assistant',
      streamEndpoint: '/chat/assistant/:thread/stream',
      ...overrides,
    }
  }

  it('reads both routes out of the payload the server rendered', () => {
    const config = resumableConnectionConfig(payload())

    expect(typeof config.url === 'function' ? config.url() : config.url).toBe('/chat/assistant')
    expect(config.streamUrl('conv-1')).toBe('/chat/assistant/conv-1/stream')
  })

  it('encodes the thread id it puts in the stream route', () => {
    const config = resumableConnectionConfig(payload())

    expect(config.streamUrl('conv 1/2?x')).toBe('/chat/assistant/conv%201%2F2%3Fx/stream')
  })

  it('reads the payload per turn, so a replaced one is followed', () => {
    let source = payload()
    const config = resumableConnectionConfig(() => source)

    source = payload({
      endpoint: '/chat/other',
      streamEndpoint: '/chat/other/:thread/stream',
    })

    expect(typeof config.url === 'function' ? config.url() : config.url).toBe('/chat/other')
    expect(config.streamUrl('conv-1')).toBe('/chat/other/conv-1/stream')
  })

  it('takes the options beside the payload', () => {
    const config = resumableConnectionConfig(payload(), {
      csrfToken: 'token-1',
      idleAttempts: 2,
    })

    expect(config).toMatchObject({ csrfToken: 'token-1', idleAttempts: 2 })
  })

  it('refuses a payload carrying no stream endpoint', () => {
    expect(() =>
      resumableConnectionConfig({
        endpoint: '/chat/assistant',
      }).streamUrl('conv-1'),
    ).toThrow('The chat payload does not carry a stream endpoint to resume from.')
  })

  it('posts a turn to one route and follows the thread on the other', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(acceptedResponse({ threadId: 'conv 1', runId: 'run-1', offset: 2 })),
    )

    const controller = new AbortController()
    const read = scripted(controller)
    const connection = createResumableConnection({ ...resumableConnectionConfig(payload()), read, csrf })
    const accepted = await connection(request())

    await connection
      .attach?.({
        threadId: accepted?.threadId ?? '',
        offset: accepted?.offset ?? 0,
        signal: controller.signal,
        onEvent: () => {},
      })
      .catch(() => {})

    expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/chat/assistant')
    expect(urls(read)).toEqual(['/chat/assistant/conv%201/stream?offset=2&follow=0'])
  })
})
