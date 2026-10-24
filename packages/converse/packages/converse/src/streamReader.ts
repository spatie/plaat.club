import { SseStreamIdleError } from './errors'
import type { AgUiEvent, ChatStreamEnd, ChatStreamFrame } from './types'

/**
 * What a frame of a JSON event stream carries: an AG-UI event, or the sentinel a read of a thread's stream ends on.
 */
export type JsonStreamEvent = AgUiEvent | ChatStreamEnd

/**
 * The request a JSON event stream is read with.
 */
export type JsonEventStreamRequest = {
  url: string
  method?: 'GET' | 'POST'
  body?: Record<string, unknown>
  signal: AbortSignal
  headers?: Record<string, string>
  credentials?: RequestCredentials
  csrfToken?: string
  csrf?: CsrfHeaders
  /**
   * How long, in milliseconds, a resumable read waits for the next frame. Defaults to the follow window the stream
   * route advertises plus five seconds, or 45 seconds where it advertises none.
   */
  readIdleTimeout?: number
  onEvent: (event: JsonStreamEvent, frame: ChatStreamFrame) => void
  onResponse?: (response: Response) => void
  onParseError?: (error: Error, data: string) => void
}

/**
 * A reader that sends a request and hands over each server-sent event parsed as JSON.
 */
export type JsonEventStreamReader = (request: JsonEventStreamRequest) => Promise<unknown>

/**
 * Builds the headers a request is signed with.
 */
export type CsrfHeaders = (options?: { csrfToken?: string }) => Record<string, string>

/**
 * The options every connection in this package accepts.
 */
export type ConnectionOptions = {
  headers?: Record<string, string>
  credentials?: RequestCredentials
  csrfToken?: string
}

/**
 * How much longer than the server's follow window a read waits for the next byte.
 */
const READ_IDLE_MARGIN = 5000

/**
 * How long a read waits for the next byte where the server advertises no follow window.
 */
const DEFAULT_READ_IDLE_TIMEOUT = 45000

/**
 * The header the stream route advertises its follow window under.
 */
const FOLLOW_WINDOW_HEADER = 'X-Converse-Follow-Window-Milliseconds'

/**
 * Build the reader a thread's stream is followed with, giving up on a read that goes quiet.
 */
export function resumableReaderFor(read: JsonEventStreamReader): JsonEventStreamReader {
  return async ({
    url,
    method,
    body,
    signal,
    headers,
    credentials,
    csrfToken,
    csrf,
    readIdleTimeout,
    onEvent,
    onResponse,
    onParseError,
  }) => {
    const controller = new AbortController()

    let timeout = readIdleTimeout ?? DEFAULT_READ_IDLE_TIMEOUT
    let timer: ReturnType<typeof setTimeout> | undefined
    let giveUp: ((error: SseStreamIdleError) => void) | undefined

    // Reset by the frame rather than by the byte: the stream route writes whole frames and nothing at all
    // between them, so a window that carried no frame is a window that carried no bytes.
    const waitForFrame = (): void => {
      clearTimeout(timer)

      // Rejected before the abort, so the idle error is what the read gives back rather than whatever
      // the reader makes of a cancelled fetch.
      timer = setTimeout(() => {
        giveUp?.(new SseStreamIdleError(timeout))
        controller.abort()
      }, timeout)
    }

    const abort = (): void => controller.abort(signal.reason)

    if (signal.aborted) {
      abort()
    } else {
      signal.addEventListener('abort', abort, { once: true })
    }

    const idle = new Promise<never>((_resolve, reject) => {
      giveUp = reject
    })

    // Armed before the request goes out, so a server that never answers with headers is given up on too.
    waitForFrame()

    // Raced rather than awaited, so a reader that does not notice the abort still gives the read back.
    return Promise.race([
      read({
        url,
        method,
        body,
        signal: controller.signal,
        headers,
        credentials,
        csrfToken,
        csrf,
        onParseError,
        onResponse: (response) => {
          timeout = readIdleTimeout ?? followWindowTimeout(response.headers)

          waitForFrame()
          onResponse?.(response)
        },
        onEvent: (event, frame) => {
          waitForFrame()
          onEvent(event, frame)
        },
      }),
      idle,
    ])
      .catch((error: unknown) => {
        // A read that ended by a throw may have left its response open, which the abort closes.
        controller.abort(error)

        throw error
      })
      .finally(() => {
        clearTimeout(timer)
        signal.removeEventListener('abort', abort)
      })
  }
}

/**
 * Get how long a read may wait for the next byte, from the follow window the server advertised.
 */
function followWindowTimeout(headers: Headers): number {
  const advertised = Number(headers.get(FOLLOW_WINDOW_HEADER))

  return Number.isFinite(advertised) && advertised > 0 ? advertised + READ_IDLE_MARGIN : DEFAULT_READ_IDLE_TIMEOUT
}
