/** Stopping a run, here and on the server. */

import { describe, expect, it } from 'vitest'
import { ChatRequestError } from '../src/errors'
import type { ChatConnection, ChatConnectionRequest, ChatRunRef } from '../src/types'
import { transport, deferred, attachable, emitFrames, sourceRef, chatWith, settle } from './chatHarness'

/*
 * `cancel()` used to abort the browser's fetch and nothing else, so the run kept answering, kept the
 * thread's claim, and refused the next send. It is a request of its own now, and only the local path
 * still merely drops the read.
 */
describe('cancel', () => {
  /** A run held open, reporting the run it is following and recording the stops the store sends it. */
  function stoppable(options: { fail?: Error; accept?: Promise<void> } = {}) {
    const requests: ChatConnectionRequest[] = []
    const stopped: ChatRunRef[] = []
    const held = deferred()

    const connection: ChatConnection = async (request) => {
      requests.push(request)

      await options.accept

      request.onEvent({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      request.onEvent({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm1', delta: 'Half' })

      await held.promise
    }

    connection.cancel = async (run) => {
      stopped.push(run)

      if (options.fail !== undefined) {
        throw options.fail
      }
    }

    return { connection, requests, stopped, held }
  }

  it('names the run the server minted, and keeps reading until the server ends it', async () => {
    const { connection, requests, stopped, held } = stoppable()
    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()
    await chat.cancel()

    expect(stopped).toEqual([{ threadId: 'conv-1', runId: 'run-1' }])
    // Still attached: the offset converges on the stream's own end rather than stopping wherever this read was.
    expect(requests[0].signal.aborted).toBe(false)

    held.resolve()
    await running

    expect(chat.status).toBe('ready')
  })

  it('shares one request between concurrent stops of the same run', async () => {
    const { connection, stopped, held } = stoppable()
    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()
    await Promise.all([chat.cancel(), chat.cancel(), chat.cancel()])

    expect(stopped).toHaveLength(1)

    held.resolve()
    await running
  })

  it('waits for the send to be accepted before it names a run', async () => {
    const accepted = deferred()
    const { connection, stopped, held } = stoppable({ accept: accepted.promise })
    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')
    const stopping = chat.cancel()

    await settle()

    expect(stopped).toEqual([])

    accepted.resolve()
    await stopping

    expect(stopped).toEqual([{ threadId: 'conv-1', runId: 'run-1' }])

    held.resolve()
    await running
  })

  it('stops nothing when the send it was waiting on was refused', async () => {
    const { connection, next } = transport()
    const stopped: ChatRunRef[] = []

    connection.cancel = async (run) => {
      stopped.push(run)
    }

    next(() => {
      throw new ChatRequestError('Chat thread [conv-1] was not found.', 404)
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')
    const stopping = chat.cancel()

    await running
    await stopping

    expect(stopped).toEqual([])
    expect(chat.status).toBe('error')
  })

  it('reports a stop the server refused, rather than pretending the run ended', async () => {
    const errors: Error[] = []
    const failure = new ChatRequestError('Chat thread [conv-1] was not found.', 404)
    const { connection, requests, held } = stoppable({ fail: failure })

    const chat = chatWith(sourceRef(), { connection, onError: (error) => errors.push(error) })
    const running = chat.send('Hello')

    await settle()
    await chat.cancel()

    expect(chat.error).toBe('Chat thread [conv-1] was not found.')
    expect(errors).toEqual([failure])
    expect(requests[0].signal.aborted).toBe(false)

    held.resolve()
    await running
  })

  it('drops the read without stopping the run when it is only detaching', async () => {
    const { connection, requests, stopped, held } = stoppable()
    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()
    chat.store.detach()

    expect(stopped).toEqual([])
    expect(requests[0].signal.aborted).toBe(true)

    held.resolve()
    await running
  })

  it('keeps a thread switch on the local path, since dropping a thread is not stopping its run', async () => {
    const { connection, requests, stopped, held } = stoppable()
    const source = sourceRef()
    const chat = chatWith(source, { connection })
    const running = chat.send('Hello')

    await settle()
    source.current = { endpoint: '/chat', threadId: 'conv-2' }
    chat.syncSource()

    expect(stopped).toEqual([])
    expect(requests[0].signal.aborted).toBe(true)
    expect(chat.messages).toEqual([])

    held.resolve()
    await running
  })

  it('renders a stopped run as stopped when a later reader replays it', async () => {
    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
      }),
      {
        connection: attachable((request) =>
          emitFrames(request, [
            { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm1', delta: 'Half an ans' },
            { type: 'RUN_CANCELLED', threadId: 'conv-1', runId: 'run-1' },
          ]),
        ),
      },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => message.status)).toEqual(['cancelled'])
    expect(chat.messages.map((message) => message.content)).toEqual(['Half an ans'])
  })
})
