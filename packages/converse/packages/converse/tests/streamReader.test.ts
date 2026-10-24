/**
 * The wrapper the thread's stream is followed through: the idle timer, and the signal the reader is run on.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { SseStreamIdleError } from '../src/errors'
import { resumableReaderFor, type JsonEventStreamReader, type JsonStreamEvent } from '../src/streamReader'
import type { ChatStreamFrame } from '../src/types'
import { csrf, emitAt, frame, silentRead, streamResponse, untilAborted } from './streamDoubles'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('the resumable reader', () => {
  it('passes the request through to the reader, and the frames back', async () => {
    const read = vi.fn<JsonEventStreamReader>().mockImplementation(async ({ onResponse, onEvent }) => {
      onResponse?.(streamResponse({}))

      onEvent(
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        frame(17, null, { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' }),
      )
      onEvent(
        { reason: 'up-to-date', offset: 18, live: false },
        frame(18, 'converse-stream-end', { reason: 'up-to-date', offset: 18, live: false }),
      )
    })

    const seen: { event: JsonStreamEvent; frame: ChatStreamFrame }[] = []

    await resumableReaderFor(read)({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      headers: { 'X-Agent': 'assistant' },
      credentials: 'include',
      csrfToken: 'token-1',
      csrf,
      onEvent: (event, streamFrame) => {
        seen.push({ event, frame: streamFrame })
      },
    })

    expect(read.mock.calls[0][0]).toMatchObject({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      credentials: 'include',
      csrfToken: 'token-1',
      csrf,
      headers: { 'X-Agent': 'assistant' },
    })
    expect(seen).toEqual([
      {
        event: {
          type: 'RUN_STARTED',
          threadId: 'conv-1',
          runId: 'run-1',
        },
        frame: {
          event: null,
          id: '17',
          data: '{"type":"RUN_STARTED","threadId":"conv-1","runId":"run-1"}',
        },
      },
      {
        event: { reason: 'up-to-date', offset: 18, live: false },
        frame: {
          event: 'converse-stream-end',
          id: '18',
          data: '{"reason":"up-to-date","offset":18,"live":false}',
        },
      },
    ])
  })

  it('runs the reader on a signal of its own, so one read may be given up on', async () => {
    const read = vi.fn<JsonEventStreamReader>().mockResolvedValue(undefined)
    const controller = new AbortController()

    await resumableReaderFor(read)({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: controller.signal,
      onEvent: () => {},
    })

    expect(read.mock.calls[0][0].signal).not.toBe(controller.signal)
  })

  // The timer is reset by every frame, so a stream that keeps delivering is never cut off.
  it('gives up on a stream that opened and then sent nothing', async () => {
    vi.useFakeTimers()

    const signals: AbortSignal[] = []

    const failed = resumableReaderFor(silentRead({ 'X-Converse-Follow-Window-Milliseconds': '1000' }, signals))({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      onEvent: () => {},
    }).then(
      () => null,
      (error: unknown) => error,
    )

    // The server cuts a silent follow at the window it advertised, so the read waits that out and a little more.
    await vi.advanceTimersByTimeAsync(5_000)

    expect(signals[0].aborted).toBe(false)

    await vi.advanceTimersByTimeAsync(1_000)

    expect(await failed).toBeInstanceOf(SseStreamIdleError)
    expect(signals[0].aborted).toBe(true)
  })

  it('waits far longer for a server that advertises no follow window', async () => {
    vi.useFakeTimers()

    let settled = false
    const failed = resumableReaderFor(silentRead({}))({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      onEvent: () => {},
    }).then(
      () => null,
      (error: unknown) => {
        settled = true

        return error
      },
    )

    await vi.advanceTimersByTimeAsync(44_000)

    expect(settled).toBe(false)

    await vi.advanceTimersByTimeAsync(1_000)

    expect(await failed).toBeInstanceOf(SseStreamIdleError)
  })

  it('aborts the fetch the reader is running when the read is abandoned', async () => {
    vi.useFakeTimers()

    const signals: AbortSignal[] = []
    const controller = new AbortController()

    const failed = resumableReaderFor(silentRead({}, signals))({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: controller.signal,
      onEvent: () => {},
    }).then(
      () => null,
      (error: unknown) => error,
    )

    await vi.advanceTimersByTimeAsync(0)

    controller.abort()

    await vi.advanceTimersByTimeAsync(0)

    expect(await failed).toBeInstanceOf(DOMException)
    expect(signals[0].aborted).toBe(true)
  })
  it('aborts the fetch the reader is running when the read ends by a throw', async () => {
    const signals: AbortSignal[] = []

    const read: JsonEventStreamReader = async ({ signal, onEvent }) => {
      signals.push(signal)

      emitAt(onEvent, 1, { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })

      return untilAborted(signal)
    }

    const reading = resumableReaderFor(read)({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      onEvent: () => {
        throw new Error('The handler failed.')
      },
    })

    await expect(reading).rejects.toThrow('The handler failed.')
    expect(signals[0].aborted).toBe(true)
  })

  it('gives up on a server that never answers with headers', async () => {
    vi.useFakeTimers()

    const signals: AbortSignal[] = []
    const neverAnswers: JsonEventStreamReader = async ({ signal }) => {
      signals.push(signal)

      return untilAborted(signal)
    }

    const failed = resumableReaderFor(neverAnswers)({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      onEvent: () => {},
    }).then(
      () => null,
      (error: unknown) => error,
    )

    await vi.advanceTimersByTimeAsync(44_000)

    expect(signals[0].aborted).toBe(false)

    await vi.advanceTimersByTimeAsync(1_000)

    expect(await failed).toBeInstanceOf(SseStreamIdleError)
    expect(signals[0].aborted).toBe(true)
  })

  it('waits the idle timeout it was given before headers arrive', async () => {
    vi.useFakeTimers()

    const failed = resumableReaderFor(async ({ signal }) => untilAborted(signal))({
      url: '/chat/agent/conv-1/stream',
      method: 'GET',
      signal: new AbortController().signal,
      readIdleTimeout: 2_000,
      onEvent: () => {},
    }).then(
      () => null,
      (error: unknown) => error,
    )

    await vi.advanceTimersByTimeAsync(2_000)

    expect(await failed).toMatchObject({ name: 'SseStreamIdleError', timeout: 2_000 })
  })
})
