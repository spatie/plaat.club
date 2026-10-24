// @vitest-environment jsdom

/**
 * The component forwarding its options, mounted rather than introspected.
 *
 * Declaring a prop and handing it to `useChat` are separate mistakes, and only
 * a mounted component tells them apart.
 */

import type { AgUiMessage, ChatAttachRequest, ChatConnection, ChatMessage } from '@laravel/converse'
import { describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref, type App } from 'vue'
import Chat from '../src/Chat.vue'
import type { ChatSlotProps } from '../src/types'

type Props = InstanceType<typeof Chat>['$props']

function mount(props: Props | (() => Props)): { app: App; slot: () => ChatSlotProps } {
  const container = document.createElement('div')
  let latest: ChatSlotProps | undefined

  const app = createApp({
    render: () =>
      h(Chat, typeof props === 'function' ? props() : props, {
        default: (slotProps: ChatSlotProps) => {
          latest = slotProps

          return []
        },
      }),
  })

  app.mount(container)

  return { app, slot: () => latest as ChatSlotProps }
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

    const { app, slot } = mount({
      chat: { endpoint: '/chat' },
      connection,
      onFinish: (message: ChatMessage) => finished.push(message),
    })

    await slot().send('Hello')

    expect(bodies).toHaveLength(1)
    expect(bodies[0]).toMatchObject({ messages: [expect.objectContaining({ content: 'Hello' })] })
    expect(finished.map((message) => message.content)).toEqual(['Hi'])

    app.unmount()
  })

  it('applies a changed queue prop to the next busy message without re-mounting', async () => {
    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => (release = resolve))
    const prompts: unknown[] = []

    const connection: ChatConnection = async ({ body }) => {
      prompts.push((body.messages as { content: string }[])[0].content)

      await held
    }

    const queue = ref<'drop' | 'queue'>('drop')
    const { app, slot } = mount(() => ({ chat: { endpoint: '/chat' }, connection, queue: queue.value }))
    const send = slot().send
    const running = send('First')

    expect(await send('Second')).toBe(false)

    queue.value = 'queue'
    await nextTick()

    expect(await slot().send('Third')).toBe(true)
    expect(slot().send).toBe(send)
    expect(slot().queued.map((message) => message.content)).toEqual(['Third'])
    expect(slot().messages.map((message) => message.content)).toContain('First')

    release()
    await running
    await vi.waitFor(() => expect(prompts).toEqual(['First', 'Third']))

    app.unmount()
  })

  it('reports a transport failure to the handler it was given', async () => {
    const failures: Error[] = []

    const connection: ChatConnection = () => Promise.reject(new Error('Network down'))

    const { app, slot } = mount({
      chat: { endpoint: '/chat' },
      connection,
      onError: (error: Error) => failures.push(error),
    })

    await slot().send('Hello')

    expect(failures.map((error) => error.message)).toEqual(['Network down'])

    app.unmount()
  })

  it('reads the transcript from a history prop instead of the payload', () => {
    const { app, slot } = mount({
      chat: {
        endpoint: '/chat',
        messages: [payloadMessage('payload-1')],
      },
      history: [storedMessage('history-1')],
      connection: (() => new Promise(() => {})) as ChatConnection,
    })

    expect(slot().messages.map((message) => message.id)).toEqual(['history-1'])

    app.unmount()
  })

  it('still reads the payload when no history prop is given', () => {
    const { app, slot } = mount({
      chat: {
        endpoint: '/chat',
        messages: [payloadMessage('payload-1')],
      },
      connection: (() => new Promise(() => {})) as ChatConnection,
    })

    expect(slot().messages.map((message) => message.id)).toEqual(['payload-1'])

    app.unmount()
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

    const { app, slot } = mount({
      chat: { endpoint: '/chat' },
      connection,
      onEvent: (event: { type: string }) => seen.push(event.type),
    })

    await slot().send('Hello')

    expect(seen).toEqual(['TEXT_MESSAGE_CONTENT', 'RUN_FINISHED'])

    app.unmount()
  })
})

describe('catching up', () => {
  /** A connection that can also catch up on a thread, recording what it was asked to attach to. */
  function attachable(attached: ChatAttachRequest[]): ChatConnection {
    const connection: ChatConnection = async () => {}

    connection.attach = async (request) => {
      attached.push(request)

      // A tick before the first frame, the way a request is: the component
      // renders once while the catch-up is still in flight.
      await Promise.resolve()

      request.onEvent(
        {
          type: 'TEXT_MESSAGE_CONTENT',
          messageId: 'm-1',
          delta: 'Caught up',
        },
        { event: null, id: '4', data: '' },
      )
    }

    return connection
  }

  /** Let a catch-up that is already in flight finish, as a render would. */
  function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0))
  }

  it('catches up on mount from the offset the payload names', async () => {
    const attached: ChatAttachRequest[] = []

    const { app, slot } = mount({
      chat: {
        endpoint: '/chat',
        threadId: 'conv-1',
        offset: 3,
      },
      connection: attachable(attached),
    })

    // Disabled until the catch-up is done, which is what `resuming` is for.
    expect(slot().status).toBe('resuming')
    // The page names the position its transcript was rendered at, which is where the read starts.
    expect(slot().session).toEqual({ threadId: 'conv-1', offset: 3 })

    await flush()

    expect(attached.map((request) => request.threadId)).toEqual(['conv-1'])
    expect(attached[0].offset).toBe(3)
    expect(slot().status).toBe('ready')
    expect(slot().messages[0].content).toBe('Caught up')

    app.unmount()
  })

  it('stays put when the page names no offset', async () => {
    const attached: ChatAttachRequest[] = []

    const { app, slot } = mount({
      chat: { endpoint: '/chat', threadId: 'conv-1', offset: null },
      connection: attachable(attached),
    })

    await flush()

    expect(attached).toEqual([])
    expect(slot().status).toBe('ready')
    expect(slot().session).toBeNull()

    app.unmount()
  })

  it('hands the slot an attach it can call itself', async () => {
    const attached: ChatAttachRequest[] = []

    const { app, slot } = mount({
      chat: { endpoint: '/chat', threadId: 'conv-1', offset: 3 },
      connection: attachable(attached),
    })

    await flush()
    await slot().attach()

    // Once on mount, and once more for the slot's own call.
    expect(attached.map((request) => request.threadId)).toEqual(['conv-1', 'conv-1'])

    app.unmount()
  })

  it('hands the slot a sync that picks the thread up again from where it read to', async () => {
    const attached: ChatAttachRequest[] = []

    const { app, slot } = mount({
      chat: { endpoint: '/chat', threadId: 'conv-1', offset: 3 },
      connection: attachable(attached),
    })

    await flush()
    await slot().sync()

    expect(attached.map((request) => [request.threadId, request.offset])).toEqual([
      ['conv-1', 3],
      ['conv-1', 4],
    ])

    app.unmount()
  })
})

describe('the transport options', () => {
  /*
   * These reach the transport rather than the store, so the only thing
   * worth pinning is that the component does not swallow them.
   */
  it('are accepted without a connection of their own', () => {
    const spy = vi.spyOn(console, 'warn').mockImplementation(() => {})

    const { app, slot } = mount({
      chat: { endpoint: '/chat' },
      headers: { 'X-Persona': 'terse' },
      csrfToken: 'token-1',
      credentials: 'include',
    })

    expect(slot().status).toBe('ready')
    expect(spy).not.toHaveBeenCalled()

    app.unmount()
  })

  it('sends a changed header on the next turn', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 500 }))

    vi.stubGlobal('fetch', fetch)

    const headers = ref({ 'X-Persona': 'terse' })
    const { app, slot } = mount(() => ({
      chat: { endpoint: '/chat', streamEndpoint: '/chat/:thread/stream' },
      headers: headers.value,
    }))

    await slot().send('First')
    headers.value = { 'X-Persona': 'chatty' }
    await nextTick()
    await slot().send('Second')

    expect(fetch.mock.calls.map((call) => call[1].headers['X-Persona'])).toEqual(['terse', 'chatty'])

    vi.unstubAllGlobals()
    app.unmount()
  })
})
