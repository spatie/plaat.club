// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest'
import { StreamResponseError, csrfHeaders, readJsonEvents } from '../src/eventStream'
import type { ChatStreamFrame } from '../src/types'

function streamOf(
  chunks: string[],
  headers: Record<string, string> = { 'Content-Type': 'text/event-stream' },
): Response {
  const encoder = new TextEncoder()

  return new Response(
    new ReadableStream({
      start(controller) {
        for (const chunk of chunks) {
          controller.enqueue(encoder.encode(chunk))
        }

        controller.close()
      },
    }),
    { headers },
  )
}

async function read(response: Response, options: { method?: 'GET' | 'POST'; signal?: AbortSignal } = {}) {
  const fetch = vi.fn().mockResolvedValue(response)
  const events: unknown[] = []
  const frames: ChatStreamFrame[] = []
  const malformed: string[] = []

  vi.stubGlobal('fetch', fetch)

  await readJsonEvents({
    url: '/converse/assistant/web',
    method: options.method,
    body: { threadId: 'conv-1' },
    signal: options.signal ?? new AbortController().signal,
    onEvent: (event, frame) => {
      events.push(event)
      frames.push(frame)
    },
    onParseError: (_error, data) => malformed.push(data),
  })

  return { fetch, events, frames, malformed }
}

afterEach(() => {
  vi.unstubAllGlobals()
  document.head.innerHTML = ''
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('readJsonEvents', () => {
  it('posts the body as JSON and asks for an event stream', async () => {
    const { fetch } = await read(streamOf([]))

    expect(fetch).toHaveBeenCalledWith(
      '/converse/assistant/web',
      expect.objectContaining({ method: 'POST', credentials: 'same-origin', body: '{"threadId":"conv-1"}' }),
    )
    expect(fetch.mock.calls[0][1].headers).toMatchObject({
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
    })
  })

  it('sends no body on a GET', async () => {
    const { fetch } = await read(streamOf([]), { method: 'GET' })

    expect(fetch.mock.calls[0][1]).not.toHaveProperty('body')
    expect(fetch.mock.calls[0][1].headers).not.toHaveProperty('Content-Type')
  })

  it('signs the request with the csrf headers it is given', async () => {
    const fetch = vi.fn().mockResolvedValue(streamOf([]))

    vi.stubGlobal('fetch', fetch)

    await readJsonEvents({
      url: '/converse/assistant/web',
      method: 'GET',
      signal: new AbortController().signal,
      csrf: () => ({ 'X-Signed': 'yes' }),
      onEvent: () => {},
    })

    expect(fetch.mock.calls[0][1].headers).toMatchObject({ 'X-Signed': 'yes' })
  })

  it('hands over each frame parsed, with its event name and the id the stream last set', async () => {
    const { events, frames } = await read(
      streamOf([
        'id: 1\ndata: {"type":"RUN_STARTED"}\n\n',
        ': keep-alive\n\n',
        'data: {"type":"TEXT_MESSAGE_END"}\n\n',
        'event: converse-stream-end\nid: 2\ndata: {"reason":"up-to-date","offset":2,"live":false}\n\n',
      ]),
    )

    expect(events).toEqual([
      { type: 'RUN_STARTED' },
      { type: 'TEXT_MESSAGE_END' },
      { reason: 'up-to-date', offset: 2, live: false },
    ])
    expect(frames.map(({ event, id }) => [event, id])).toEqual([
      [null, '1'],
      [null, '1'],
      ['converse-stream-end', '2'],
    ])
  })

  it('reassembles frames split across reads, CRLF line endings and multi-line data', async () => {
    const { events } = await read(
      streamOf(['id: 1\r', '\ndata: {"type":', '"A"}\r\n\r\n', 'data: {"type":\ndata: "B"}\n\n', 'data: {"type":"C"}']),
    )

    // The unterminated last frame never reached its blank line, so it is dropped.
    expect(events).toEqual([{ type: 'A' }, { type: 'B' }])
  })

  it('reports a frame that is not JSON and keeps reading', async () => {
    const { events, malformed } = await read(streamOf(['data: nope\n\n', 'data: {"type":"A"}\n\n']))

    expect(malformed).toEqual(['nope'])
    expect(events).toEqual([{ type: 'A' }])
  })

  it('throws the status and body of a refused response', async () => {
    const refused = read(new Response('{"message":"Busy."}', { status: 409 }))

    await expect(refused).rejects.toBeInstanceOf(StreamResponseError)
    await expect(refused).rejects.toMatchObject({
      status: 409,
      body: '{"message":"Busy."}',
      message: '{"message":"Busy."}',
    })
  })

  it('names the status of a refused response without a body', async () => {
    await expect(read(new Response('', { status: 500 }))).rejects.toThrow('The stream request failed with status 500.')
  })

  it('refuses a response that is not an event stream', async () => {
    await expect(read(new Response('{}', { headers: { 'Content-Type': 'application/json' } }))).rejects.toThrow(
      'Expected a text/event-stream response, received "application/json".',
    )
  })

  it('refuses a response without a body', async () => {
    await expect(read(new Response(null, { headers: { 'Content-Type': 'text/event-stream' } }))).rejects.toThrow(
      'The stream response has no body to read.',
    )
  })

  it('stops reading and throws once the signal aborts', async () => {
    const controller = new AbortController()
    const response = streamOf(['data: {"type":"A"}\n\ndata: {"type":"B"}\n\n'])
    const events: unknown[] = []

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response))

    const reading = readJsonEvents({
      url: '/converse/assistant/web',
      signal: controller.signal,
      onEvent: (event) => {
        events.push(event)
        controller.abort()
      },
    })

    await expect(reading).rejects.toMatchObject({ name: 'AbortError' })
    expect(events).toEqual([{ type: 'A' }])
  })

  it('strips only the single space after a field colon', async () => {
    const { frames } = await read(streamOf(['data:  {"type":"A"} \n\n']))

    expect(frames.map((frame) => frame.data)).toEqual([' {"type":"A"} '])
  })

  it('takes the id from a frame that carries no data', async () => {
    const { frames } = await read(streamOf(['id: 7\n\n', 'data: {"type":"A"}\n\n']))

    expect(frames.map((frame) => frame.id)).toEqual(['7'])
  })

  it('ignores an id containing a NUL', async () => {
    const { frames } = await read(streamOf(['id: 7\n\n', 'id: a\u0000b\ndata: {"type":"A"}\n\n']))

    expect(frames.map((frame) => frame.id)).toEqual(['7'])
  })

  it('does not report a throw from the handler as a malformed frame', async () => {
    const malformed: string[] = []

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(streamOf(['data: {"type":"A"}\n\n', 'data: nope\n\n'])))

    const reading = readJsonEvents({
      url: '/converse/assistant/web',
      signal: new AbortController().signal,
      onEvent: () => {
        throw new Error('The handler failed.')
      },
      onParseError: (_error, data) => malformed.push(data),
    })

    await expect(reading).rejects.toThrow('The handler failed.')
    expect(malformed).toEqual([])
  })

  it('closes the response once the handler throws, rather than leaving it open', async () => {
    const cancel = vi.fn()
    const encoder = new TextEncoder()

    const body = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(encoder.encode('data: {"type":"A"}\n\n'))
      },
      cancel,
    })

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(body, { headers: { 'Content-Type': 'text/event-stream' } })),
    )

    const reading = readJsonEvents({
      url: '/converse/assistant/web',
      signal: new AbortController().signal,
      onEvent: () => {
        throw new Error('The handler failed.')
      },
    })

    await expect(reading).rejects.toThrow('The handler failed.')
    expect(cancel).toHaveBeenCalledOnce()
  })
})

describe('csrfHeaders', () => {
  it('prefers the token it is given', () => {
    document.head.innerHTML = '<meta name="csrf-token" content="from-meta">'

    expect(csrfHeaders({ csrfToken: 'given' })).toEqual({ 'X-CSRF-TOKEN': 'given' })
  })

  it("reads the page's csrf-token meta tag", () => {
    document.head.innerHTML = '<meta name="csrf-token" content="from-meta">'

    expect(csrfHeaders()).toEqual({ 'X-CSRF-TOKEN': 'from-meta' })
  })

  it("falls back to Laravel's XSRF-TOKEN cookie, decoded", () => {
    document.cookie = 'XSRF-TOKEN=abc%3D%3D'

    expect(csrfHeaders()).toEqual({ 'X-XSRF-TOKEN': 'abc==' })
  })

  it('signs nothing without a token', () => {
    expect(csrfHeaders()).toEqual({})
  })
})
