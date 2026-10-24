/**
 * The transport a payload's chat runs on, and the part of it that is Laravel's: turning a refused turn
 * into a `ChatRequestError` a form can read.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { connectionsFor, transportFor } from '../src/connection'
import { ChatRequestError, toRequestError } from '../src/errors'
import type { JsonEventStreamRequest } from '../src/streamReader'
import type { ChatConnection, ChatSource } from '../src/types'
import { FakeFailure, acceptedResponse, csrf, endRead, isFailure, request } from './streamDoubles'

function payload(overrides: Partial<ChatSource> = {}): ChatSource {
  return {
    endpoint: '/chat/assistant',
    streamEndpoint: '/chat/assistant/:thread/stream',
    ...overrides,
  }
}

/** A reader that catches up once and then stops the follow, the way a page going away does. */
function caughtUpRead(controller: AbortController) {
  return vi.fn().mockImplementation(async ({ onEvent }: JsonEventStreamRequest) => {
    endRead(onEvent, 0)
    controller.abort()
  })
}

/** Follow a thread through the connection until the reader stops it. */
function follow(connection: ChatConnection, controller: AbortController): Promise<unknown> {
  return Promise.resolve(
    connection.attach?.({ threadId: 'conv-1', offset: 0, signal: controller.signal, onEvent: () => {} }),
  ).catch((error: unknown) => error)
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('the default connection', () => {
  it('can follow a thread and stop a run', () => {
    const { connectionFor } = connectionsFor({ read: vi.fn(), csrf, isResponseFailure: isFailure })

    const connection = connectionFor(() => payload())

    expect(connection.attach).toBeInstanceOf(Function)
    expect(connection.cancel).toBeInstanceOf(Function)
  })

  it('reads the payload per request, so a replaced one is followed', async () => {
    const fetch = vi
      .fn()
      .mockImplementation(async () => acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 0 }))

    vi.stubGlobal('fetch', fetch)

    const first = new AbortController()
    const second = new AbortController()
    const read = vi.fn()
    const { connectionFor } = connectionsFor({ read, csrf, isResponseFailure: isFailure })

    let source = payload()
    const connection = connectionFor(() => source)

    read.mockImplementationOnce(caughtUpRead(first))
    await connection(request())
    await follow(connection, first)

    source = payload({ endpoint: '/chat/other', streamEndpoint: '/chat/other/:thread/stream' })
    read.mockImplementationOnce(caughtUpRead(second))
    await connection(request())
    await follow(connection, second)

    expect(fetch.mock.calls.map((call) => call[0])).toEqual(['/chat/assistant', '/chat/other'])
    expect(read.mock.calls.map((call) => call[0].url)).toEqual([
      '/chat/assistant/conv-1/stream?offset=0&follow=0',
      '/chat/other/conv-1/stream?offset=0&follow=0',
    ])
  })

  it('forwards the headers, credentials and CSRF token it was built with', async () => {
    const fetch = vi.fn().mockResolvedValue(acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 0 }))

    vi.stubGlobal('fetch', fetch)

    const controller = new AbortController()
    const read = caughtUpRead(controller)
    const { connectionFor } = connectionsFor({ read, csrf, isResponseFailure: isFailure })
    const connection = connectionFor(() => payload(), {
      headers: { 'X-Persona': 'terse' },
      credentials: 'include',
      csrfToken: 'token-1',
    })

    await connection(request())
    await follow(connection, controller)

    expect(fetch.mock.calls[0][1]).toMatchObject({
      credentials: 'include',
      headers: { 'X-CSRF-TOKEN': 'token-1', 'X-Persona': 'terse' },
    })
    expect(read.mock.calls[0][0]).toMatchObject({
      headers: { 'X-Persona': 'terse' },
      credentials: 'include',
      csrfToken: 'token-1',
    })
  })

  it("reports a refused read through the adapter's own failure predicate", async () => {
    const read = vi.fn().mockRejectedValue(new FakeFailure(422, JSON.stringify({ message: 'That is too long.' })))
    const { connectionFor } = connectionsFor({ read, csrf, isResponseFailure: isFailure })

    await expect(
      follow(
        connectionFor(() => payload()),
        new AbortController(),
      ),
    ).resolves.toMatchObject({
      name: 'ChatRequestError',
      status: 422,
      message: 'That is too long.',
    })
  })
})

describe('a refused turn', () => {
  it('becomes a ChatRequestError carrying the validation errors of a 422', () => {
    const error = toRequestError({
      status: 422,
      body: JSON.stringify({
        message: 'The message field is required.',
        errors: { message: ['Required.'] },
      }),
    })

    expect(error).toMatchObject({
      name: 'ChatRequestError',
      status: 422,
      message: 'The message field is required.',
      errors: { message: ['Required.'] },
    })
  })

  it('carries the approvals an approval mismatch says are still pending', () => {
    const approvals = [{ id: 'call-1', tool: 'send_email', arguments: { to: 'a@b.com' } }]

    const error = toRequestError({
      status: 409,
      body: JSON.stringify({ message: 'Stale.', code: 'approval_mismatch', approvals }),
    })

    expect(error).toBeInstanceOf(ChatRequestError)
    expect(error.code).toBe('approval_mismatch')
    expect(error.isApprovalMismatch).toBe(true)
    expect(error.approvals).toEqual(approvals)
  })

  it('is not an approval mismatch for a 409 that names no mismatch', () => {
    const error = toRequestError({
      status: 409,
      body: JSON.stringify({ message: 'Busy.', threadId: 'conv-1', runId: 'run-1', offset: 3 }),
    })

    expect(error.code).toBeNull()
    expect(error.isApprovalMismatch).toBe(false)
  })

  it('is not an approval mismatch for any other status', () => {
    const error = toRequestError({ status: 500, body: JSON.stringify({ message: 'Boom.' }) })

    expect(error.isApprovalMismatch).toBe(false)
  })

  it('falls back to a message naming the status when the body is not JSON', () => {
    expect(toRequestError({ status: 500, body: '<html>Server Error</html>' })).toMatchObject({
      status: 500,
      message: 'The chat endpoint failed with status 500.',
      errors: {},
      approvals: [],
    })
  })

  it('falls back to that message when the body is JSON without one', () => {
    expect(toRequestError({ status: 503, body: JSON.stringify({ errors: {} }) })).toMatchObject({
      message: 'The chat endpoint failed with status 503.',
    })
  })
})

describe('the transport a chat is given options for', () => {
  it('reads the options afresh for every request', async () => {
    const fetch = vi
      .fn()
      .mockImplementation(async () => acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 0 }))

    vi.stubGlobal('fetch', fetch)

    let headers = { 'X-Tenant': 'one' }
    const connection = transportFor(
      () => payload(),
      () => ({ headers }),
    )

    await connection(request())
    headers = { 'X-Tenant': 'two' }
    await connection(request())

    const sent = fetch.mock.calls.filter((call) => call[0] === '/chat/assistant').map((call) => call[1].headers)

    expect(sent).toEqual([
      expect.objectContaining({ 'X-Tenant': 'one' }),
      expect.objectContaining({ 'X-Tenant': 'two' }),
    ])
  })

  it('keeps following after a send made while it caught up, so the turn that send started is not missed', async () => {
    const controller = new AbortController()
    const reads: string[] = []
    let send = async (): Promise<unknown> => undefined

    const end = (offset: number, live: boolean): Response =>
      new Response(`event: converse-stream-end\ndata: ${JSON.stringify({ reason: 'up-to-date', offset, live })}\n\n`, {
        headers: { 'Content-Type': 'text/event-stream', 'X-Converse-Follow-Window-Milliseconds': '1000' },
      })

    vi.spyOn(Math, 'random').mockReturnValue(0)
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(async (url: string) => {
        if (!url.includes('/stream')) {
          return acceptedResponse({ threadId: 'conv-1', runId: 'run-1', offset: 3 })
        }

        reads.push(url)

        if (reads.length === 1) {
          await send()

          return end(3, false)
        }

        controller.abort()

        return end(4, true)
      }),
    )

    const connection = transportFor(
      () => payload(),
      () => ({ headers: { 'X-Tenant': 'one' } }),
    )

    send = () => connection(request())

    await follow(connection, controller)

    expect(reads).toEqual([
      '/chat/assistant/conv-1/stream?offset=0&follow=0',
      '/chat/assistant/conv-1/stream?offset=3&follow=1',
    ])
  })

  it('hands every request to the connection it is currently given', async () => {
    const first = vi.fn(async () => {})
    const second = vi.fn(async () => {})
    let connection: ChatConnection = first

    const transport = transportFor(
      () => payload(),
      () => ({ connection }),
    )

    await transport(request())
    connection = second
    await transport(request())

    expect(first).toHaveBeenCalledOnce()
    expect(second).toHaveBeenCalledOnce()
    expect(transport.attach).toBeUndefined()
    expect(transport.cancel).toBeUndefined()
  })

  it("forwards the given connection's attach and cancel", async () => {
    const given: ChatConnection = async () => {}
    const attach = vi.fn(async () => {})
    const cancel = vi.fn(async () => {})

    given.attach = attach
    given.cancel = cancel

    const transport = transportFor(
      () => payload(),
      () => ({ connection: given }),
    )

    await transport.attach?.({
      threadId: 'conv-1',
      offset: 0,
      signal: new AbortController().signal,
      onEvent: () => {},
    })
    await transport.cancel?.({ threadId: 'conv-1', runId: 'run-1' })

    expect(attach).toHaveBeenCalledOnce()
    expect(cancel).toHaveBeenCalledWith({ threadId: 'conv-1', runId: 'run-1' })
  })

  it('refuses a connection handed options it will never read, as soon as it is built', () => {
    expect(() =>
      transportFor(
        () => payload(),
        () => ({ connection: async () => {}, headers: { 'X-Tenant': 'one' } }),
      ),
    ).toThrow('A chat given its own [connection] never reads [headers]')
  })
})
