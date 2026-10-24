/**
 * The doubles the transport suites are written against, being a reader, the failure class it reports a
 * refusal with, and the responses a server answers a turn with.
 */

import type { StreamResponseFailure } from '../src/errors'
import type { JsonEventStreamReader, JsonEventStreamRequest } from '../src/streamReader'
import type { AgUiEvent, ChatAcceptedTurn, ChatStreamEnd, ChatStreamFrame } from '../src/types'

export class FakeFailure extends Error {
  constructor(
    public readonly status: number,
    public readonly body: string,
  ) {
    super(`HTTP ${status}`)
  }
}

export const isFailure = (error: unknown): error is StreamResponseFailure => error instanceof FakeFailure

/**
 * The CSRF headers the transport signs its own requests with.
 */
export const csrf = ({ csrfToken }: { csrfToken?: string } = {}): Record<string, string> =>
  csrfToken === undefined ? {} : { 'X-CSRF-TOKEN': csrfToken }

export function request(overrides: { onEvent?: (event: AgUiEvent) => void } = {}) {
  return {
    body: { threadId: 'conv-1' },
    signal: new AbortController().signal,
    onEvent: overrides.onEvent ?? ((): void => {}),
  }
}

export function acceptedResponse(turn: ChatAcceptedTurn): Response {
  return new Response(JSON.stringify(turn), {
    status: 202,
    headers: { 'Content-Type': 'application/json' },
  })
}

export function frame(id: number | string | null, event: string | null, data: unknown): ChatStreamFrame {
  return {
    event,
    id: id === null ? null : String(id),
    data: JSON.stringify(data),
  }
}

/**
 * The sentinel a read of the thread's stream ends on.
 */
export function upToDate(offset: number, live = false): ChatStreamEnd {
  return { reason: 'up-to-date', offset, live }
}

/**
 * Hand the reader's `onEvent` the sentinel a read ends on, as the stream route writes it.
 */
export function endRead(onEvent: JsonEventStreamRequest['onEvent'], offset: number, live = false): void {
  const sentinel = upToDate(offset, live)

  onEvent(sentinel, frame(null, 'converse-stream-end', sentinel))
}

/**
 * Hand the reader's `onEvent` a sentinel the route would never write, as the JSON the frame carried.
 */
export function endReadWith(onEvent: JsonEventStreamRequest['onEvent'], sentinel: unknown): void {
  onEvent(sentinel as ChatStreamEnd, frame(null, 'converse-stream-end', sentinel))
}

/**
 * Hand the reader's `onEvent` an event, under the stream position the route wrote it at.
 */
export function emitAt(onEvent: JsonEventStreamRequest['onEvent'], sequence: number, event: AgUiEvent): void {
  onEvent(event, frame(sequence, null, event))
}

export function streamResponse(headers: Record<string, string> = {}): Response {
  return new Response('', {
    headers: { 'Content-Type': 'text/event-stream', ...headers },
  })
}

/**
 * Stay open until the signal is aborted, the way a fetch whose server has gone quiet does.
 */
export function untilAborted(signal: AbortSignal): Promise<never> {
  return new Promise((_resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')), {
      once: true,
    })
  })
}

/**
 * A reader that opens a stream with the given headers on it and then never sends a byte.
 */
export function silentRead(headers: Record<string, string>, signals: AbortSignal[] = []): JsonEventStreamReader {
  return async ({ signal, onResponse }) => {
    signals.push(signal)
    onResponse?.(streamResponse(headers))

    return untilAborted(signal)
  }
}
