/** A run that fails or reports an error, and retrying it. */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ChatRequestError } from '../src/errors'
import type { ChatConnection, ChatMessage } from '../src/types'
import {
  transport,
  promptIn,
  deferred,
  payloadMessage,
  emitFrames,
  sourceRef,
  chatWith,
  settle,
  threadServer,
  settleAttach,
} from './chatHarness'

afterEach(() => {
  vi.restoreAllMocks()
})

describe('failure', () => {
  it('records a failed turn on the message and on the store', async () => {
    const { connection, next } = transport()
    const errors: Error[] = []

    next(() => {
      throw new Error('The provider fell over.')
    })

    const chat = chatWith(sourceRef(), {
      connection,
      onError: (error) => errors.push(error),
    })

    await chat.send('Hello')

    expect(chat.status).toBe('error')
    expect(chat.error).toBe('The provider fell over.')
    expect(chat.messages[1].status).toBe('error')
    expect(errors).toHaveLength(1)
  })

  it('fails a turn the connection accepted without a way to read its answer', async () => {
    const errors: Error[] = []
    const onFinish = vi.fn()
    const connection: ChatConnection = async () => ({ threadId: 'conv-1', runId: 'run-1', offset: 0 })

    const chat = chatWith(sourceRef({ capabilities: { retry: true } }), {
      connection,
      onFinish,
      onError: (error) => errors.push(error),
    })

    await chat.send('Hello')

    const reason = 'The chat connection accepted the turn, but has no attach to read its answer with.'

    expect(chat.status).toBe('error')
    expect(chat.error).toBe(reason)
    expect(chat.messages[1]).toMatchObject({ status: 'error', error: reason })
    expect(errors.map((error) => error.message)).toEqual([reason])
    expect(onFinish).not.toHaveBeenCalled()
    expect(chat.store.getState().can.retry).toBe(true)
  })

  it('clears the field errors of an earlier refusal once the thread’s stream fails', async () => {
    const server = threadServer()
    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), { connection: server.connection })

    await settleAttach(chat.store, server)

    server.refuse(new ChatRequestError('Invalid.', 422, { message: ['The message field is required.'] }))
    await chat.send('Hello')

    expect(chat.validationErrors).toEqual({ message: ['The message field is required.'] })

    void chat.store.sync()
    server.fail(new Error('The chat stream broke.'))
    await settle()

    expect(chat.status).toBe('error')
    expect(chat.error).toBe('The chat stream broke.')
    expect(chat.validationErrors).toEqual({})
  })

  it('exposes validation errors from a 422', async () => {
    const { connection, next } = transport()

    next(() => {
      throw new ChatRequestError('Invalid.', 422, {
        message: ['The message field is required.'],
      })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.validationErrors).toEqual({
      message: ['The message field is required.'],
    })

    chat.clearError()

    expect(chat.validationErrors).toEqual({})
    expect(chat.status).toBe('ready')
  })

  it('replays the failed turn without duplicating the prompt', async () => {
    const { connection, next, requests } = transport()

    next(() => {
      throw new Error('The provider fell over.')
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')
    await chat.retry()

    expect(requests.map(promptIn)).toEqual(['Hello', 'Hello'])
    expect(chat.messages.map((message) => message.content)).toEqual(['Hello', 'Hi'])
    expect(chat.status).toBe('ready')
  })

  it('does nothing on retry when nothing has run', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(sourceRef(), { connection })

    await chat.retry()

    expect(requests).toHaveLength(0)
  })

  it('keeps a partial answer when the run is canceled', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await held.promise

      request.signal.throwIfAborted()
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()
    chat.cancel()
    held.resolve()
    await running

    expect(chat.messages[1].content).toBe('Half')
    expect(chat.messages[1].status).toBe('complete')
    expect(chat.status).toBe('ready')
  })

  it('marks an answer that never started as canceled', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (_emit, request) => {
      await held.promise

      request.signal.throwIfAborted()
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    chat.cancel()
    held.resolve()
    await running

    expect(chat.messages[1].status).toBe('error')
    expect(chat.messages[1].error).toBe('Canceled.')
  })
})

/*
 * `lastRun` lives in memory, so after a reload it is gone and the store has only the transcript to go on.
 * The prompt is stored before the turn runs and a stopped or failed turn never writes an answer, so a
 * conversation that ends on a user message is one nobody answered, whatever went wrong.
 */
describe('retry', () => {
  it('offers a retry for a transcript that ends on a prompt nobody answered', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        capabilities: { retry: true },
        messages: [
          payloadMessage({
            id: 'stored-user',
            role: 'user',
            content: 'Unanswered',
          }),
        ],
      }),
      { connection },
    )

    expect(chat.can.retry).toBe(true)

    await chat.retry()

    // Sent under the id the row already has, which is what lets the server reuse it rather than store it twice.
    expect(requests[0].body.messages).toEqual([{ id: 'stored-user', role: 'user', content: 'Unanswered' }])
    expect(chat.messages.map((message) => message.content)).toEqual(['Unanswered', 'Hi'])
  })

  it('offers none for a transcript that ends on an answer', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(
      sourceRef({ threadId: 'conv-1', capabilities: { retry: true }, messages: [payloadMessage()] }),
      { connection },
    )

    expect(chat.can.retry).toBe(false)

    await chat.retry()

    expect(requests).toHaveLength(0)
  })

  it('offers none while the answer is still arriving', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit) => {
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Half' })

      await held.promise
    })

    const chat = chatWith(sourceRef({ capabilities: { retry: true } }), { connection })
    const running = chat.send('Hello')

    await settle()

    expect(chat.can.retry).toBe(false)

    held.resolve()
    await running

    expect(chat.can.retry).toBe(false)
  })

  it('offers none while a queued prompt waits behind the answer that is arriving', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit) => {
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Half' })

      await held.promise
    })

    const chat = chatWith(sourceRef({ capabilities: { retry: true } }), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    expect(chat.streaming).toBe(true)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Second'])
    expect(chat.can.retry).toBe(false)

    held.resolve()
    await running

    expect(chat.can.retry).toBe(false)
  })

  /*
   * What the maintainer hit. The run was stopped, the page was reloaded, and the answer that was on screen
   * was never history: only the prompt comes back. Replaying the stream puts the stopped answer back under it,
   * and the retry replaces that rather than stacking a second one beneath it.
   */
  it('replays a run the user stopped, once the page has forgotten it', async () => {
    const { connection, requests } = transport()

    connection.attach = async (request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        { type: 'TEXT_MESSAGE_START', messageId: 'stored-user', role: 'user' },
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'stored-user', delta: 'Tell me' },
        { type: 'TEXT_MESSAGE_END', messageId: 'stored-user' },
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Half an ans' },
        { type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-1' },
      ])
    }

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        capabilities: { retry: true },
        offset: 0,
        messages: [
          payloadMessage({
            id: 'stored-user',
            role: 'user',
            content: 'Tell me',
          }),
        ],
      }),
      { connection },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => [message.content, message.status])).toEqual([
      ['Tell me', 'complete'],
      ['Half an ans', 'cancelled'],
    ])
    expect(chat.can.retry).toBe(true)

    await chat.retry()

    expect(requests[0].body.messages).toEqual([{ id: 'stored-user', role: 'user', content: 'Tell me' }])
    expect(chat.messages.map((message) => [message.content, message.status])).toEqual([
      ['Tell me', 'complete'],
      ['Hi', 'complete'],
    ])
  })
})

describe('a run that reports an error', () => {
  it('fails the answer, reports the error once and holds the queue back', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()
    const errors: Error[] = []

    next(async (emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'TOOL_CALL_START', toolCallId: 'call-1', toolCallName: 'Search' })

      await held.promise

      emit({ type: 'RUN_ERROR', message: 'The provider fell over.', code: 'provider_failed' })
    })

    const chat = chatWith(sourceRef(), { connection, onError: (error) => errors.push(error) })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running

    expect(requests).toHaveLength(1)
    expect(chat.status).toBe('error')
    expect(chat.error).toBe('The provider fell over.')
    expect(chat.errorCode).toBe('provider_failed')
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Second'])
    expect(errors.map((error) => error.message)).toEqual(['The provider fell over.'])
    expect(chat.messages[1]).toMatchObject({ status: 'error', error: 'The provider fell over.' })
    expect(chat.messages[1].toolCalls[0].state).toBe('error')
  })

  it('never reports a finish for the answer it failed', async () => {
    const { connection, next } = transport()
    const finished: ChatMessage[] = []

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'RUN_ERROR', message: 'An error occurred.' })
    })

    const chat = chatWith(sourceRef(), { connection, onFinish: (message) => finished.push(message) })

    await chat.send('Hello')

    expect(finished).toEqual([])
  })
})
