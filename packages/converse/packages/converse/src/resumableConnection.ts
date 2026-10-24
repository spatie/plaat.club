import {
  ChatRequestError,
  ChatStreamStalledError,
  ChatThreadBusyError,
  SseStreamIdleError,
  toRequestError,
  type StreamResponseFailure,
} from './errors'
import { isStreamResponseError, requestInit } from './eventStream'
import { frameSequence, numericOffset } from './session'
import type { ConnectionOptions, CsrfHeaders, JsonEventStreamReader, JsonStreamEvent } from './streamReader'
import type {
  AgUiEvent,
  ChatAcceptedTurn,
  ChatAttachRequest,
  ChatConnection,
  ChatConnectionRequest,
  ChatRunRef,
  ChatSession,
  ChatSource,
  ChatStreamEnd,
  ChatStreamFrame,
  ChatStreamPosition,
} from './types'

/**
 * The configuration a resumable connection is created with.
 */
export type ResumableConnectionConfig = ResumableConnectionOptions & {
  url: string | (() => string)
  streamUrl: (threadId: string) => string
  read: JsonEventStreamReader
  csrf: CsrfHeaders
  /**
   * How the reader reports a response the server refused, which defaults to this package's own reader.
   */
  isResponseFailure?: (error: unknown) => error is StreamResponseFailure
}

/**
 * The options a resumable connection takes beside its payload.
 */
export type ResumableConnectionOptions = {
  [Option in keyof ConnectionOptions]?: ConnectionOptions[Option] | (() => ConnectionOptions[Option])
} & {
  onOffset?: (session: ChatSession) => void
  /**
   * How many reads in a row may fail before the connection stops following the thread.
   */
  idleAttempts?: number
  /**
   * How long, in milliseconds, a read waits for the next frame. Defaults to the follow window the stream route
   * advertises plus five seconds, or 45 seconds where it advertises none.
   */
  readIdleTimeout?: number
}

/**
 * What a following read needs from the request it is serving.
 */
type FollowRequest = Pick<ChatAttachRequest, 'signal' | 'onEvent' | 'onUpToDate'>

/**
 * What one read of the stream ended with: the sentinel it caught up to, or a cut-off or transient failure before it,
 * having applied a frame or not.
 */
type ReadOutcome =
  | { kind: 'caught-up'; position: ChatStreamPosition; poll: number | null }
  | { kind: 'incomplete'; progressed: boolean; failure: unknown }

/**
 * How many reads in a row may fail before the connection gives up.
 */
const DEFAULT_IDLE_ATTEMPTS = 5

/**
 * The most, in milliseconds, a follower waits before reconnecting, so tabs whose reads ended together spread out.
 */
const RECONNECT_JITTER = 250

/**
 * What a stream endpoint carries where the thread id belongs.
 */
const THREAD_PLACEHOLDER = ':thread'

/**
 * The suffix the package's stream route ends on, which the cancel route beside it replaces.
 */
const STREAM_SUFFIX = '/stream'

/**
 * Build the configuration for a resumable connection from the payload the server rendered.
 */
export function resumableConnectionConfig(
  source: ChatSource | (() => ChatSource),
  options: ResumableConnectionOptions = {},
): Omit<ResumableConnectionConfig, 'read' | 'csrf' | 'isResponseFailure'> {
  const read = (): ChatSource => (typeof source === 'function' ? source() : source)

  return {
    ...options,
    url: () => read().endpoint,
    streamUrl: (threadId) => streamEndpointFor(read(), threadId),
  }
}

/**
 * Create a connection that sends turns and follows a thread's durable stream, reconnecting where it is cut off.
 */
export function createResumableConnection({
  url,
  streamUrl,
  read,
  csrf,
  isResponseFailure = isStreamResponseError,
  onOffset,
  headers,
  credentials,
  csrfToken,
  idleAttempts = DEFAULT_IDLE_ATTEMPTS,
  readIdleTimeout,
}: ResumableConnectionConfig): ChatConnection {
  // Counted so a read that found nothing live while a send claimed the thread is followed by another.
  let sends = 0

  /**
   * Get the headers, credentials and CSRF token to send, read afresh for every request.
   */
  const requestOptions = (): ConnectionOptions => ({
    headers: typeof headers === 'function' ? headers() : headers,
    credentials: typeof credentials === 'function' ? credentials() : credentials,
    csrfToken: typeof csrfToken === 'function' ? csrfToken() : csrfToken,
  })

  /**
   * Determine if the error is a response the server refused.
   */
  const refused = (error: unknown): error is StreamResponseFailure => isResponseFailure(error)

  /**
   * Read the thread's stream once from the offset, handing each new frame over and moving the offset past it.
   */
  const readOnce = async (
    threadId: string,
    position: { offset: number; bounded: boolean },
    request: FollowRequest,
  ): Promise<ReadOutcome> => {
    let end: ChatStreamPosition | null = null
    let poll: number | null = null
    let progressed = false
    let delivering = false
    let failure: unknown = null
    // The interrupts that close a snapshot travel under the snapshot's own position.
    let snapshotAt: number | null = null

    /**
     * Hand the frame over and move the offset past it, where a throw is the caller's own failure.
     */
    const deliver = (event: AgUiEvent, frame: ChatStreamFrame, sequence: number): void => {
      delivering = true
      request.onEvent(event, frame)

      position.offset = sequence
      progressed = true
      onOffset?.({ threadId, offset: sequence })
      delivering = false
    }

    try {
      await read({
        url: withStreamOffset(streamUrl(threadId), position.offset, position.bounded),
        method: 'GET',
        signal: request.signal,
        ...requestOptions(),
        csrf,
        readIdleTimeout,
        onEvent: (event, frame) => {
          if (isStreamEnd(event, frame)) {
            end = streamPosition(event)
            poll = pollInterval(event)

            return
          }

          const sequence = frameSequence(frame)

          if (sequence === null) {
            throw new Error('A resumable chat stream frame is missing its SSE id.')
          }

          // A snapshot restarts the stream at its own position, even one behind an offset the thread no longer has.
          const restarts = event.type === 'MESSAGES_SNAPSHOT'

          // A reconnect may overlap the frame the last read already applied.
          if (!restarts && sequence <= position.offset && sequence !== snapshotAt) {
            return
          }

          snapshotAt = event.type === 'MESSAGES_SNAPSHOT' ? sequence : null

          deliver(event, frame, sequence)
        },
      })
    } catch (error) {
      // A throw from the caller's handlers is the app's own failure, not a dropped connection.
      if (request.signal.aborted || delivering) {
        throw error
      }

      if (!isTransientStreamError(error, refused)) {
        throw refused(error) ? toRequestError(error) : error
      }

      failure = error
    }

    // A sentinel answers the read, even where the connection dropped after it.
    if (end !== null) {
      return { kind: 'caught-up', position: end, poll }
    }

    return { kind: 'incomplete', progressed, failure }
  }

  /**
   * Follow the thread's stream from the offset until the signal is aborted, catching up with a bounded read first.
   */
  const follow = async (threadId: string, offset: number, request: FollowRequest): Promise<void> => {
    const position = { offset, bounded: true }
    let attempt = 0
    // The budget rather than the backoff: `attempt` shapes how long to wait, this whether to wait at all.
    let failures = 0

    for (;;) {
      request.signal.throwIfAborted()

      const sendsBefore = sends
      const outcome = await readOnce(threadId, position, request)

      // A read that failed or was cut off before its sentinel left the bounded question unanswered, so the
      // retry asks it again. One that applied a frame was progress, which the budget starts over from.
      if (outcome.kind === 'incomplete') {
        failures = outcome.progressed ? 0 : failures + 1

        if (failures > idleAttempts) {
          throw new ChatStreamStalledError(failures, outcome.failure)
        }

        await waitForBackoff(outcome.progressed ? 0 : attempt, request.signal)
        attempt = outcome.progressed ? 1 : attempt + 1

        continue
      }

      failures = 0
      attempt = 0

      if (outcome.position.offset > position.offset) {
        position.offset = outcome.position.offset
        onOffset?.({ threadId, offset: position.offset })
      }

      request.onUpToDate?.({ offset: position.offset, live: outcome.position.live })

      // Nothing live is the end of the follow, unless a send claimed the thread after the server last looked.
      if (!outcome.position.live && sends === sendsBefore) {
        return
      }

      if (outcome.poll !== null) {
        await sleep(outcome.poll, request.signal)

        continue
      }

      // The catch-up goes straight on to following; a follow that ran out its window waits a little first.
      if (!position.bounded) {
        await sleep(Math.floor(Math.random() * RECONNECT_JITTER), request.signal)
      }

      position.bounded = false
    }
  }

  const connection: ChatConnection = async (request: ChatConnectionRequest) => {
    try {
      return await openTurn({
        url: typeof url === 'function' ? url() : url,
        body: request.body,
        signal: request.signal,
        ...requestOptions(),
        csrf,
      })
    } catch (error) {
      throw busyThread(error) ?? error
    } finally {
      sends += 1
    }
  }

  /**
   * Stop the run for good, which settles it on the thread's stream and releases its claim on the thread.
   */
  connection.cancel = async ({ threadId, runId }: ChatRunRef): Promise<void> => {
    // No signal: a page that is going away still wants the run it started to stop.
    const response = await fetch(
      cancelEndpointFor(streamUrl(threadId), runId),
      requestInit({ method: 'POST', accept: 'application/json', ...requestOptions(), csrf }),
    )

    if (!response.ok) {
      throw toRequestError({ status: response.status, body: await response.text() })
    }
  }

  /**
   * Follow a thread's stream from the offset, every run on it included.
   */
  connection.attach = (request: ChatAttachRequest): Promise<void> => follow(request.threadId, request.offset, request)

  return connection
}

/**
 * Get the stream route for a thread, filling the payload's template in with it.
 */
function streamEndpointFor(source: ChatSource, threadId: string): string {
  const template = source.streamEndpoint

  if (typeof template !== 'string' || template === '') {
    throw new Error('The chat payload does not carry a stream endpoint to resume from.')
  }

  return template.replace(THREAD_PLACEHOLDER, encodeURIComponent(threadId))
}

/**
 * Get the cancel route for a run, which this package registers beside the stream route it is named next to.
 */
function cancelEndpointFor(streamEndpoint: string, runId: string): string {
  return rewriteUrl(streamEndpoint, (parsed) => {
    if (!parsed.pathname.endsWith(STREAM_SUFFIX)) {
      throw new Error('The chat stream route is not one a cancel route can be derived from.')
    }

    parsed.pathname = `${parsed.pathname.slice(0, -STREAM_SUFFIX.length)}/runs/${encodeURIComponent(runId)}/cancel`
    parsed.search = ''
  })
}

/**
 * Get the refusal for a thread another run is answering, or null where the refusal is something else.
 */
function busyThread(error: unknown): ChatThreadBusyError | null {
  if (!(error instanceof ChatRequestError) || error.status !== 409) {
    return null
  }

  let payload: unknown

  try {
    payload = JSON.parse(error.body)
  } catch {
    return null
  }

  // A duplicate or a run that settled before the server could name it names no run to wait for.
  if (!isRecord(payload) || typeof payload.threadId !== 'string' || typeof payload.runId !== 'string') {
    return null
  }

  const offset = numericOffset(payload.offset)

  return offset === null ? null : new ChatThreadBusyError(payload.threadId, payload.runId, offset)
}

/**
 * Send the turn, and get the run the server accepted it as.
 */
async function openTurn({
  url,
  body,
  signal,
  headers,
  credentials,
  csrfToken,
  csrf,
}: {
  url: string
  body: Record<string, unknown>
  signal: AbortSignal
  headers?: Record<string, string>
  credentials?: RequestCredentials
  csrfToken?: string
  csrf: CsrfHeaders
}): Promise<ChatAcceptedTurn> {
  const response = await fetch(
    url,
    requestInit({ method: 'POST', accept: 'application/json', body, signal, headers, credentials, csrfToken, csrf }),
  )

  if (!response.ok) {
    throw toRequestError({ status: response.status, body: await response.text() })
  }

  return parseAcceptedTurn(await response.json())
}

/**
 * Parse the turn the send route accepted.
 */
function parseAcceptedTurn(value: unknown): ChatAcceptedTurn {
  if (!isRecord(value) || typeof value.threadId !== 'string' || typeof value.runId !== 'string') {
    throw new Error('The chat endpoint did not return an accepted turn.')
  }

  const offset = numericOffset(value.offset)

  if (offset === null) {
    throw new Error('The chat endpoint returned an accepted turn without an offset.')
  }

  return { threadId: value.threadId, runId: value.runId, offset }
}

/**
 * Determine if the frame is the sentinel that says the read caught up, rather than an event of the thread.
 */
function isStreamEnd(event: JsonStreamEvent, frame: ChatStreamFrame): event is ChatStreamEnd {
  return frame.event === 'converse-stream-end'
}

/**
 * Get where the sentinel says the read caught up to, checking it carries what the route writes.
 */
function streamPosition(end: ChatStreamEnd): ChatStreamPosition {
  // The reader parsed the frame without checking it, so the fields are read as the JSON they arrived as.
  const sentinel: unknown = end
  const offset = isRecord(sentinel) ? numericOffset(sentinel.offset) : null

  if (
    !isRecord(sentinel) ||
    sentinel.reason !== 'up-to-date' ||
    offset === null ||
    typeof sentinel.live !== 'boolean'
  ) {
    throw new Error('The chat stream sent an invalid converse-stream-end sentinel.')
  }

  return { offset, live: sentinel.live }
}

/**
 * Get how long, in milliseconds, the sentinel asks the reader to wait before reading again, or null where it streams.
 */
function pollInterval(end: ChatStreamEnd): number | null {
  const interval: unknown = end.poll

  return typeof interval === 'number' && Number.isFinite(interval) && interval > 0 ? interval : null
}

/**
 * Add the offset to the stream URL, asking for a bounded read where the caller is catching up.
 */
function withStreamOffset(url: string, offset: number, bounded: boolean): string {
  return rewriteUrl(url, (parsed) => {
    parsed.searchParams.set('offset', String(offset))
    parsed.searchParams.set('follow', bounded ? '0' : '1')
  })
}

/**
 * Change a URL, keeping a relative one relative.
 */
function rewriteUrl(url: string, rewrite: (parsed: URL) => void): string {
  const absolute = /^[a-z][a-z0-9+.-]*:/i.test(url)
  const parsed = new URL(url, 'http://localhost')

  rewrite(parsed)

  return absolute ? parsed.toString() : `${parsed.pathname}${parsed.search}${parsed.hash}`
}

/**
 * Determine if the failure is one a reconnect may recover from.
 */
function isTransientStreamError(error: unknown, refused: (error: unknown) => error is StreamResponseFailure): boolean {
  if (error instanceof TypeError || error instanceof SseStreamIdleError) {
    return true
  }

  if (!refused(error)) {
    return false
  }

  return error.status === 408 || error.status === 425 || error.status === 429 || error.status >= 500
}

/**
 * Wait out the backoff for the attempt, with a little jitter on it.
 */
async function waitForBackoff(attempt: number, signal: AbortSignal): Promise<void> {
  const capped = Math.min(8000, 250 * 2 ** attempt)
  const delay = Math.min(8000, capped + Math.floor(Math.random() * capped * 0.25))

  await sleep(delay, signal)
}

/**
 * Sleep for the given delay, rejecting where the read is aborted.
 */
function sleep(delay: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    signal.throwIfAborted()

    const abort = (): void => {
      clearTimeout(timeout)
      reject(signal.reason)
    }

    const timeout = setTimeout(() => {
      signal.removeEventListener('abort', abort)
      resolve()
    }, delay)

    signal.addEventListener('abort', abort, { once: true })
  })
}

/**
 * Determine if the value is an object this client may read fields from.
 */
function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object'
}
