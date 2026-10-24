/**
 * The component forwarding its options.
 *
 * Every `UseChatOptions` key but `onSessionChange` is a prop, so a page rarely has to drop to the hook
 * for one callback. Destructuring one and forwarding it are separate mistakes.
 */

import type { AgUiMessage, ChatAttachRequest, ChatConnection, ChatMessage } from '@laravel/converse'
import { act, cleanup, render, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { Chat } from '../src/Chat'
import type { ChatProps, ChatRenderProps } from '../src/types'

let latest: ChatRenderProps

function renderChat(
  props: Omit<ChatProps, 'chat' | 'children'> & {
    chat?: ChatProps['chat']
  },
) {
  render(
    <Chat chat={{ endpoint: '/chat' }} {...props}>
      {(rendered) => {
        latest = rendered

        return null
      }}
    </Chat>,
  )
}

function storedMessage(id: string): ChatMessage {
  return {
    id,
    role: 'assistant',
    content: 'Stored.',
    reasoning: '',
    status: 'complete',
    toolCalls: [],
    approvals: [],
    citations: [],
    usage: null,
    error: null,
    provider: null,
    model: null,
    createdAt: null,
    parts: [{ type: 'text', text: 'Stored.' }],
  }
}

function payloadMessage(id: string): AgUiMessage {
  return { id, role: 'assistant', content: 'Stored.' }
}

const holds: ChatConnection = () => new Promise(() => {})

afterEach(cleanup)

describe('<Chat>', () => {
  it('sends the turn through a connection it was given', async () => {
    const bodies: Record<string, unknown>[] = []
    const finished: ChatMessage[] = []

    const connection: ChatConnection = async ({ body, onEvent }) => {
      bodies.push(body)
      onEvent({
        type: 'RUN_STARTED',
        threadId: 'conv-1',
        runId: 'run-1',
      })
      onEvent({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Hi',
      })
      onEvent({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
      })
    }

    renderChat({
      connection,
      onFinish: (message) => finished.push(message),
    })

    await act(() => latest.send('Hello'))

    expect(bodies).toHaveLength(1)
    expect(bodies[0]).toMatchObject({ messages: [expect.objectContaining({ content: 'Hello' })] })
    expect(finished.map((message) => message.content)).toEqual(['Hi'])
  })

  it('applies a changed queue prop to the next busy message without re-mounting', async () => {
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => (release = resolve))
    const prompts: unknown[] = []

    const connection: ChatConnection = async ({ body }) => {
      prompts.push((body.messages as { content: string }[])[0].content)

      await held
    }

    const chat = (queue: ChatProps['queue']) => (
      <Chat chat={{ endpoint: '/chat' }} connection={connection} queue={queue}>
        {(rendered) => {
          latest = rendered

          return null
        }}
      </Chat>
    )

    const { rerender } = render(chat('drop'))
    const send = latest.send
    let running: Promise<boolean> = Promise.resolve(true)

    await act(async () => {
      running = send('First')
    })

    expect(await act(() => send('Second'))).toBe(false)

    rerender(chat('queue'))

    expect(await act(() => latest.send('Third'))).toBe(true)
    expect(latest.send).toBe(send)
    expect(latest.queued.map((message) => message.content)).toEqual(['Third'])
    expect(latest.messages.map((message) => message.content)).toContain('First')

    await act(async () => {
      release()
      await running
    })

    await waitFor(() => expect(prompts).toEqual(['First', 'Third']))
  })

  it('reports a transport failure to the handler it was given', async () => {
    const failures: Error[] = []

    renderChat({
      connection: () => Promise.reject(new Error('Network down')),
      onError: (error) => failures.push(error),
    })

    await act(() => latest.send('Hello'))

    expect(failures.map((error) => error.message)).toEqual(['Network down'])
  })

  it('hands every event to the listener it was given', async () => {
    const seen: string[] = []

    const connection: ChatConnection = async ({ onEvent }) => {
      onEvent({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm-1',
        delta: 'Hi',
      })
      onEvent({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
      })
    }

    renderChat({ connection, onEvent: (event) => seen.push(event.type) })

    await act(() => latest.send('Hello'))

    expect(seen).toEqual(['TEXT_MESSAGE_CONTENT', 'RUN_FINISHED'])
  })

  it('reads the transcript from a history prop instead of the payload', () => {
    renderChat({
      chat: {
        endpoint: '/chat',
        messages: [payloadMessage('payload-1')],
      },
      history: [storedMessage('history-1')],
      connection: holds,
    })

    expect(latest.messages.map((message) => message.id)).toEqual(['history-1'])
  })

  it('still reads the payload when no history prop is given', () => {
    renderChat({
      chat: {
        endpoint: '/chat',
        messages: [payloadMessage('payload-1')],
      },
      connection: holds,
    })

    expect(latest.messages.map((message) => message.id)).toEqual(['payload-1'])
  })

  /*
   * These reach the transport rather than the store, so the only
   * thing worth pinning is that the component does not swallow them.
   */
  it('accepts the transport options without a connection of its own', () => {
    renderChat({
      headers: { 'X-Persona': 'terse' },
      csrfToken: 'token-1',
      credentials: 'include',
    })

    expect(latest.status).toBe('ready')
  })
})

describe('catching up', () => {
  it('hands the render prop a sync that picks the thread up again from where it read to', async () => {
    const attached: ChatAttachRequest[] = []
    const connection: ChatConnection = async () => {}

    connection.attach = async (request) => {
      attached.push(request)
      request.onEvent(
        { type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Caught up' },
        { event: null, id: '4', data: '' },
      )
    }

    renderChat({ chat: { endpoint: '/chat', threadId: 'conv-1', offset: 3 }, connection })

    await act(async () => {})
    await act(() => latest.sync())

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([
      ['conv-1', 3],
      ['conv-1', 4],
    ])
  })
})
