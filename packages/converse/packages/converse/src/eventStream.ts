import type { CsrfHeaders, JsonEventStreamReader, JsonStreamEvent } from './streamReader'
import type { ChatStreamFrame } from './types'

/**
 * Thrown for a stream request the server refused, carrying the status and the body it answered with.
 */
export class StreamResponseError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: string,
  ) {
    super(body || `The stream request failed with status ${status}.`)
    this.name = 'StreamResponseError'
  }
}

/**
 * Determine if the error is a response the server refused.
 */
export function isStreamResponseError(error: unknown): error is StreamResponseError {
  return error instanceof StreamResponseError
}

/**
 * Send a request and read the server-sent events it answers with, handing each one over parsed as JSON.
 */
export const readJsonEvents: JsonEventStreamReader = async ({
  url,
  method = 'POST',
  body,
  signal,
  headers,
  credentials,
  csrfToken,
  csrf,
  onEvent,
  onResponse,
  onParseError,
}) => {
  const response = await fetch(
    url,
    requestInit({
      method,
      accept: 'text/event-stream',
      body: method === 'GET' ? undefined : (body ?? {}),
      signal,
      headers,
      credentials,
      csrfToken,
      csrf,
    }),
  )

  if (!response.ok) {
    throw new StreamResponseError(response.status, await response.text())
  }

  if (!response.body) {
    throw new Error('The stream response has no body to read.')
  }

  const contentType = response.headers.get('Content-Type') ?? ''

  if (!contentType.includes('text/event-stream')) {
    throw new Error(`Expected a text/event-stream response, received "${contentType}".`)
  }

  onResponse?.(response)

  await readFrames(response.body, (frame) => {
    if (signal.aborted) {
      return false
    }

    let event: JsonStreamEvent

    try {
      event = JSON.parse(frame.data) as JsonStreamEvent
    } catch (error) {
      onParseError?.(error as Error, frame.data)

      return true
    }

    // Outside the try, so a throw from the caller is not mistaken for a malformed frame.
    onEvent(event, frame)

    return true
  })

  signal.throwIfAborted()
}

/**
 * Build a request to the chat routes, signed and sent with the session cookie.
 */
export function requestInit({
  method,
  accept,
  body,
  signal,
  headers,
  credentials,
  csrfToken,
  csrf = csrfHeaders,
}: {
  method: 'GET' | 'POST'
  accept: string
  body?: Record<string, unknown>
  signal?: AbortSignal
  headers?: Record<string, string>
  credentials?: RequestCredentials
  csrfToken?: string
  csrf?: CsrfHeaders
}): RequestInit {
  const init: RequestInit = {
    method,
    signal,
    credentials: credentials ?? 'same-origin',
    headers: {
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      Accept: accept,
      ...csrf({ csrfToken }),
      ...headers,
    },
  }

  if (body !== undefined) {
    init.body = JSON.stringify(body)
  }

  return init
}

/**
 * Get the headers a request is signed with: the token given, the page's csrf-token meta tag, or Laravel's XSRF-TOKEN cookie.
 */
export function csrfHeaders({ csrfToken }: { csrfToken?: string } = {}): Record<string, string> {
  if (csrfToken) {
    return { 'X-CSRF-TOKEN': csrfToken }
  }

  if (typeof document === 'undefined') {
    return {}
  }

  const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

  if (meta) {
    return { 'X-CSRF-TOKEN': meta }
  }

  const cookie = document.cookie
    .split(';')
    .map((entry) => entry.trim())
    .find((entry) => entry.startsWith('XSRF-TOKEN='))

  if (!cookie) {
    return {}
  }

  // Laravel encrypts the cookie and Symfony percent-encodes it on the way out.
  return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }
}

/**
 * Read a server-sent event stream, handing each frame to the handler until it returns false or the stream ends.
 *
 * @link https://html.spec.whatwg.org/multipage/server-sent-events.html
 */
async function readFrames(
  body: ReadableStream<Uint8Array>,
  onFrame: (frame: ChatStreamFrame) => boolean,
): Promise<void> {
  const reader = body.getReader()
  const decoder = new TextDecoder()

  let buffer = ''
  let carriageReturn = ''

  // The id belongs to the stream rather than the frame: it holds until the server sets it again.
  let lastEventId: string | null = null

  try {
    for (;;) {
      const { done, value } = await reader.read()

      if (done) {
        return
      }

      buffer += carriageReturn + decoder.decode(value, { stream: true })
      carriageReturn = ''

      // Held back so a CRLF split across two reads is not turned into two line breaks.
      if (buffer.endsWith('\r')) {
        buffer = buffer.slice(0, -1)
        carriageReturn = '\r'
      }

      buffer = buffer.replace(/\r\n?/g, '\n')

      let boundary = buffer.indexOf('\n\n')

      while (boundary !== -1) {
        const fields = parseFields(buffer.slice(0, boundary))

        buffer = buffer.slice(boundary + 2)
        lastEventId = fields.id ?? lastEventId

        if (fields.data !== null && !onFrame({ event: fields.event, id: lastEventId, data: fields.data })) {
          // Not awaited: a source mid-write may not settle its cancel until it finishes.
          void reader.cancel().catch(() => undefined)

          return
        }

        boundary = buffer.indexOf('\n\n')
      }
    }
  } catch (error) {
    // A handler that throws leaves the response unread, so it is closed rather than held open.
    void reader.cancel(error).catch(() => undefined)

    throw error
  } finally {
    reader.releaseLock()
  }
}

/**
 * Parse the fields of one frame, joining its data lines.
 */
function parseFields(frame: string): { event: string | null; id: string | undefined; data: string | null } {
  const data: string[] = []

  let event: string | null = null
  let id: string | undefined

  for (const line of frame.split('\n')) {
    if (line === '' || line.startsWith(':')) {
      continue
    }

    const colon = line.indexOf(':')
    const field = colon === -1 ? line : line.slice(0, colon)
    const value = colon === -1 ? '' : line.slice(colon + 1).replace(/^ /, '')

    if (field === 'data') {
      data.push(value)
    } else if (field === 'event') {
      event = value
    } else if (field === 'id' && !value.includes('\u0000')) {
      id = value
    }
  }

  return { event, id, data: data.length === 0 ? null : data.join('\n') }
}
