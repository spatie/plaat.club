/** Sending a turn, and the messages a handler streams back. */

import { describe, expect, it } from 'vitest'
import type { ChatConnectionRequest, ChatMessage } from '../src/types'
import {
  type Script,
  transport,
  promptIn,
  deferred,
  payloadMessage,
  attachable,
  emitFrames,
  sourceRef,
  chatWith,
  settle,
} from './chatHarness'

describe('send', () => {
  it('renders the prompt before the answer arrives', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })

      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()

    expect(chat.messages.map((message) => message.content)).toEqual(['Hello', ''])
    expect(chat.status).toBe('streaming')

    held.resolve()
    await running

    expect(chat.status).toBe('ready')
  })

  /*
   * Both rows, not just the answer's. The prompt is rendered under an id this
   * browser invented, and until the run reports what it stored, anything keyed
   * on message ids sees a turn that is only half persisted.
   */
  it('adopts the stored ids the run reports, for the prompt as well as the answer', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Hi',
      })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
        messageId: 'stored-assistant',
        userMessageId: 'stored-user',
      })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-user', 'stored-assistant'])
  })

  /*
   * A reloaded page builds its store under the same framework scope, and its sequence starts over. The server
   * refuses a message id the thread has already been sent, so the second page's first message was refused as a
   * duplicate of the first page's.
   */
  it('never sends a message id another store for the same thread already sent', async () => {
    const first = transport()
    const second = transport()

    await chatWith(sourceRef({ threadId: 'conv-1' }), { connection: first.connection, scope: 'v-0' }).send('Hello')
    await chatWith(sourceRef({ threadId: 'conv-1' }), { connection: second.connection, scope: 'v-0' }).send('Hello')

    const idOf = (request: ChatConnectionRequest) => (request.body.messages as { id: string }[])[0].id

    expect(idOf(second.requests[0])).not.toBe(idOf(first.requests[0]))
  })

  it('keeps the local prompt id when the run stored nothing', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Hi',
      })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.messages[0].id).toMatch(/user/)
  })

  it('is submitted until the first event', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    expect(chat.status).toBe('submitted')
    expect(chat.streaming).toBe(true)

    held.resolve()
    await running
  })

  it('ignores an empty message', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(sourceRef(), { connection })

    await chat.send('   ')

    expect(requests).toHaveLength(0)
    expect(chat.messages).toEqual([])
  })

  it('posts the thread id the run reported', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.threadId).toBe('conv-1')

    await chat.send('Again')

    expect(requests[1].body).toMatchObject({ threadId: 'conv-1' })
    expect(promptIn(requests[1])).toBe('Again')
  })

  it('sends only the newest message, leaving history to the server', async () => {
    const { connection, requests } = transport()
    const chat = chatWith(
      sourceRef({
        messages: [payloadMessage(), payloadMessage({ id: 'stored-2' })],
      }),
      {
        connection,
      },
    )

    await chat.send('Hello')

    expect(requests[0].body.messages).toHaveLength(1)
  })

  it('takes the thread id from RUN_STARTED, before any token', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async (emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-9', runId: 'run-1' })

      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()

    expect(chat.threadId).toBe('conv-9')

    held.resolve()
    await running
  })
})

/*
 * A handler bot answers with `send()`, and every send is stored as its own row. The run has no steps, so a
 * new text message after one that closed is the next send, and the live turn renders it the way a reload will.
 */
describe('a handler that sends more than once', () => {
  function handlerSends(held: Promise<void> = Promise.resolve()): Script {
    return async (emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'TEXT_MESSAGE_START', messageId: 'sent-1', role: 'assistant' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'sent-1', delta: 'One.' })
      emit({ type: 'TEXT_MESSAGE_END', messageId: 'sent-1' })
      emit({ type: 'TEXT_MESSAGE_START', messageId: 'sent-2', role: 'assistant' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'sent-2', delta: 'Two.' })

      await held

      emit({ type: 'TEXT_MESSAGE_END', messageId: 'sent-2' })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
    }
  }

  // By everything but the id, since a live turn keeps local ids until the page's transcript retires it.
  function shape(messages: ChatMessage[]) {
    return messages.map((message) => [message.role, message.content, message.status, message.parts])
  }

  it('renders each send as its own message, the way a reload does', async () => {
    const { connection, next } = transport()

    next(handlerSends())

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    const reloaded = chatWith(
      sourceRef({
        threadId: 'conv-1',
        messages: [
          payloadMessage({ id: chat.messages[0].id, role: 'user', content: 'Hello' }),
          payloadMessage({ id: 'sent-1', content: 'One.' }),
          payloadMessage({ id: 'sent-2', content: 'Two.' }),
        ],
      }),
    )

    expect(chat.messages).toHaveLength(3)
    expect(shape(chat.messages)).toEqual(shape(reloaded.messages))
  })

  it('settles a send once the next one starts arriving', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(handlerSends(held.promise))

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('Hello')

    await settle()

    expect(chat.messages.map((message) => [message.content, message.status])).toEqual([
      ['Hello', 'complete'],
      ['One.', 'complete'],
      ['Two.', 'streaming'],
    ])

    held.resolve()
    await running

    expect(chat.messages.map((message) => message.status)).toEqual(['complete', 'complete', 'complete'])
  })

  it('keeps an agent step that closes several text blocks in one message', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'STEP_STARTED', stepName: '1' })
      emit({ type: 'TEXT_MESSAGE_START', messageId: 'block-1', role: 'assistant' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'block-1', delta: 'One.' })
      emit({ type: 'TEXT_MESSAGE_END', messageId: 'block-1' })
      emit({ type: 'TEXT_MESSAGE_START', messageId: 'block-2', role: 'assistant' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'block-2', delta: ' Two.' })
      emit({ type: 'TEXT_MESSAGE_END', messageId: 'block-2' })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1', messageId: 'stored-answer' })
    })

    const chat = chatWith(sourceRef(), { connection })

    await chat.send('Hello')

    expect(chat.messages[1].id).toBe('stored-answer')
    expect(shape(chat.messages.slice(1))).toEqual([
      ['assistant', 'One. Two.', 'complete', [{ type: 'text', text: 'One. Two.' }]],
    ])
  })

  it('replays a handler run once, leaving out the sends the page already carries', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'TEXT_MESSAGE_START', messageId: 'stored-user', role: 'user' },
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'stored-user', delta: 'Hello' },
        { type: 'TEXT_MESSAGE_END', messageId: 'stored-user' },
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' },
        { type: 'TEXT_MESSAGE_START', messageId: 'sent-1', role: 'assistant' },
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'sent-1', delta: 'One.' },
        { type: 'TEXT_MESSAGE_END', messageId: 'sent-1' },
        { type: 'TEXT_MESSAGE_START', messageId: 'sent-2', role: 'assistant' },
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'sent-2', delta: 'Two.' },
        { type: 'TEXT_MESSAGE_END', messageId: 'sent-2' },
        { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
        messages: [
          payloadMessage({ id: 'stored-user', role: 'user', content: 'Hello' }),
          payloadMessage({ id: 'sent-1', content: 'One.' }),
        ],
      }),
      { connection },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => [message.role, message.content, message.status])).toEqual([
      ['user', 'Hello', 'complete'],
      ['assistant', 'One.', 'complete'],
      ['assistant', 'Two.', 'complete'],
    ])
  })
})
