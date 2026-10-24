/**
 * The resumable transport driven through this package's own event stream reader against a stubbed `fetch`,
 * since the other transport tests inject a reader double.
 */

import { afterEach, expect, it, vi } from 'vitest'
import { resumableConnection } from '../src/connection'
import type { AgUiEvent, ChatSession, ChatStreamPosition } from '../src/types'

function acceptedResponse(): Response {
  return new Response(JSON.stringify({ threadId: 'conv-1', runId: 'run-1', offset: 6 }), {
    status: 202,
    headers: { 'Content-Type': 'application/json' },
  })
}

function streamResponse(body: string, headers: Record<string, string> = {}): Response {
  return new Response(body, {
    headers: {
      'Content-Type': 'text/event-stream',
      'X-Converse-Follow-Window-Milliseconds': '1000',
      ...headers,
    },
  })
}

function stubFetch(stream: Response): ReturnType<typeof vi.fn> {
  const fetch = vi
    .fn()
    .mockImplementation((url: string) => Promise.resolve(String(url).includes('/stream') ? stream : acceptedResponse()))

  vi.stubGlobal('fetch', fetch)

  return fetch
}

const sessions: ChatSession[] = []

const connection = resumableConnection(
  {
    endpoint: '/chat/assistant',
    streamEndpoint: '/chat/assistant/:thread/stream',
  },
  { onOffset: (session) => sessions.push(session) },
)

afterEach(() => {
  vi.unstubAllGlobals()
})

it('follows a thread through the default reader, taking its offset from the SSE id', async () => {
  const fetch = stubFetch(
    streamResponse(
      [
        'id: 7\n',
        'data: {"type":"RUN_STARTED","threadId":"conv-1","runId":"run-1"}\n\n',
        'id: 8\n',
        'data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"m-1","delta":"Hi"}\n\n',
        'event: converse-stream-end\n',
        'data: {"reason":"up-to-date","offset":8,"live":true}\n\n',
      ].join(''),
    ),
  )

  const controller = new AbortController()
  const events: unknown[] = []
  const positions: ChatStreamPosition[] = []

  const accepted = await connection({ body: { threadId: 'conv-1' }, signal: controller.signal, onEvent: () => {} })

  await connection
    .attach?.({
      threadId: 'conv-1',
      offset: accepted?.offset ?? 0,
      signal: controller.signal,
      onEvent: (event: AgUiEvent): void => {
        events.push(event)
      },
      onUpToDate: (position) => {
        positions.push(position)
        controller.abort()
      },
    })
    .catch(() => {})

  const read = fetch.mock.calls.find((call) => String(call[0]).includes('/stream'))

  expect(read?.[0]).toBe('/chat/assistant/conv-1/stream?offset=6&follow=0')
  expect(read?.[1]).toMatchObject({ method: 'GET' })
  expect(read?.[1]).not.toHaveProperty('body')
  expect(events).toEqual([
    { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
    { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Hi' },
  ])
  expect(positions).toEqual([{ offset: 8, live: true }])
  expect(sessions[sessions.length - 1]).toEqual({ threadId: 'conv-1', offset: 8 })
})
