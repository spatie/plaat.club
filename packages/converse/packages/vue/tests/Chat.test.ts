// @vitest-environment jsdom

/**
 * The renderless component's contract, mounted, where Vue's prop resolution can differ from the composable's.
 */

import { promptAttribute, type ChatConnection, type ChatConnectionRequest } from '@laravel/converse'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick } from 'vue'
import Chat from '../src/Chat.vue'
import type { ChatSlotProps } from '../src/types'

type Props = InstanceType<typeof Chat>['$props']

/** A connection that records each request and answers it with "Hi". */
function answering(requests: ChatConnectionRequest[] = []): ChatConnection {
  return async (request) => {
    requests.push(request)
    request.onEvent({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
    request.onEvent({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Hi' })
    request.onEvent({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1' })
  }
}

/** A connection that holds every run open until the test lets go. */
function holding(): { connection: ChatConnection; release: () => void } {
  let release: () => void = () => {}
  const held = new Promise<void>((resolve) => (release = resolve))

  return { connection: () => held, release }
}

/** Mount `<Chat>` with a slot that renders the transcript into a bound container, the way a page does. */
function mountChat(props: Props, attrs: Record<string, unknown> = {}) {
  let latest: ChatSlotProps | undefined

  const wrapper = mount(Chat, {
    props,
    attrs,
    slots: {
      default: (slotProps: ChatSlotProps) => {
        latest = slotProps

        return h('div', { ref: slotProps.composer.container, class: 'transcript' }, [
          ...slotProps.messages.map((message) =>
            h('p', message.role === 'user' ? { [promptAttribute]: '' } : {}, message.content),
          ),
          h('input', {
            value: slotProps.composer.draft,
            onInput: (event: Event) => (slotProps.composer.draft = (event.target as HTMLInputElement).value),
          }),
          h('div', { class: 'spacer', style: slotProps.composer.spacerStyle }),
        ])
      },
    },
  })

  return { wrapper, slot: () => latest as ChatSlotProps }
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('the default slot', () => {
  it('receives every documented prop under its camelCase name', () => {
    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: answering() })

    expect(new Set(Object.keys(slot()))).toEqual(
      new Set([
        'messages',
        'threadId',
        'session',
        'streaming',
        'status',
        'error',
        'errorCode',
        'validationErrors',
        'pendingApprovals',
        'queued',
        'can',
        'infiniteScrollProps',
        'hasPrevious',
        'loadPrevious',
        'send',
        'respond',
        'approve',
        'reject',
        'retry',
        'attach',
        'sync',
        'cancel',
        'clearError',
        'composer',
        'isAwaitingContent',
      ]),
    )

    wrapper.unmount()
  })

  it('re-renders with the answer as it arrives', async () => {
    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: answering() })

    await slot().send('Hello')
    await nextTick()

    expect(wrapper.findAll('p').map((paragraph) => paragraph.text())).toEqual(['Hello', 'Hi'])

    wrapper.unmount()
  })
})

describe('the defaults', () => {
  it('reports no previous page and a harmless loader when none is given', () => {
    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: answering() })

    expect(slot().hasPrevious).toBe(false)
    expect(() => slot().loadPrevious()).not.toThrow()

    wrapper.unmount()
  })

  it('hands the slot the previous page it was given', () => {
    const loadPrevious = vi.fn()
    const { wrapper, slot } = mountChat({
      chat: { endpoint: '/chat' },
      connection: answering(),
      hasPrevious: true,
      loadPrevious,
    })

    slot().loadPrevious()

    expect(slot().hasPrevious).toBe(true)
    expect(loadPrevious).toHaveBeenCalledOnce()

    wrapper.unmount()
  })

  // An absent prop must leave the store's own default in charge, not a value Vue filled in.
  it('queues a busy message when no queue prop is given, as the store does', async () => {
    const { connection, release } = holding()
    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection })

    const running = slot().send('First')

    expect(await slot().send('Second')).toBe(true)
    expect(slot().queued.map((message) => message.content)).toEqual(['Second'])

    release()
    await running
    wrapper.unmount()
  })
})

describe('anchoring prompts', () => {
  /** Give the bound container a box, and put the newest prompt 500px down it. */
  function sized(wrapper: ReturnType<typeof mountChat>['wrapper']): void {
    const element = wrapper.find('.transcript').element as HTMLElement

    Object.defineProperty(element, 'clientHeight', { configurable: true, value: 400 })
    Object.defineProperty(element, 'scrollHeight', { configurable: true, get: () => 1000 })

    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      return { top: this.hasAttribute(promptAttribute) ? 500 : 0 } as DOMRect
    })
  }

  function spacerHeight(wrapper: ReturnType<typeof mountChat>['wrapper']): string {
    return (wrapper.find('.spacer').element as HTMLElement).style.height
  }

  async function submit(wrapper: ReturnType<typeof mountChat>['wrapper'], slot: () => ChatSlotProps): Promise<void> {
    await wrapper.find('input').setValue('Hello')
    slot().composer.submit()
    await flushPromises()
  }

  // Boolean-typed, so an absent prop would cast to false without the default.
  it('anchors the newest prompt when the prop is absent', async () => {
    vi.stubGlobal('requestAnimationFrame', () => 1)
    vi.stubGlobal('cancelAnimationFrame', () => {})

    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: holding().connection })

    sized(wrapper)
    await submit(wrapper, slot)

    expect(spacerHeight(wrapper)).not.toBe('0px')

    wrapper.unmount()
  })

  it('leaves the transcript alone when turned off', async () => {
    const { wrapper, slot } = mountChat({
      chat: { endpoint: '/chat' },
      connection: holding().connection,
      anchorPrompts: false,
    })

    sized(wrapper)
    await submit(wrapper, slot)

    expect(spacerHeight(wrapper)).toBe('0px')

    wrapper.unmount()
  })

  it('leaves the given offset above the anchored prompt, even when it changes after mounting', async () => {
    vi.stubGlobal('requestAnimationFrame', () => 1)
    vi.stubGlobal('cancelAnimationFrame', () => {})

    const anchored = async (props: Partial<Props>): Promise<number> => {
      const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: holding().connection })

      await wrapper.setProps(props)
      sized(wrapper)
      await submit(wrapper, slot)

      const height = parseFloat(spacerHeight(wrapper))

      wrapper.unmount()

      return height
    }

    expect((await anchored({})) - (await anchored({ anchorOffset: 64 }))).toBe(40)
  })
})

describe('the composer', () => {
  it('binds the draft both ways and sends it on submit', async () => {
    const requests: ChatConnectionRequest[] = []
    const { wrapper, slot } = mountChat({ chat: { endpoint: '/chat' }, connection: answering(requests) })

    await wrapper.find('input').setValue('Hello')

    expect(slot().composer.draft).toBe('Hello')

    slot().composer.submit()
    await flushPromises()

    expect(requests.map((request) => (request.body.messages as { content: string }[])[0].content)).toEqual(['Hello'])
    expect((wrapper.find('input').element as HTMLInputElement).value).toBe('')

    wrapper.unmount()
  })
})

describe('the exposed instance', () => {
  it('reads the thread through a template ref, unwrapped', async () => {
    const { wrapper } = mountChat({ chat: { endpoint: '/chat' }, connection: answering() })

    await wrapper.vm.send('Hello')

    expect(wrapper.vm.status).toBe('ready')
    expect(wrapper.vm.messages.map((message) => message.content)).toEqual(['Hello', 'Hi'])

    wrapper.unmount()
  })

  it('writes the draft through a template ref', async () => {
    const { wrapper } = mountChat({ chat: { endpoint: '/chat' }, connection: answering() })

    wrapper.vm.composer.draft = 'From the parent'
    await nextTick()

    expect((wrapper.find('input').element as HTMLInputElement).value).toBe('From the parent')

    wrapper.unmount()
  })
})

describe('the options it forwards', () => {
  it('sends the CSRF token and credentials it was given with the default transport', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 500 }))

    vi.stubGlobal('fetch', fetch)

    const { wrapper, slot } = mountChat({
      chat: { endpoint: '/chat', streamEndpoint: '/chat/:thread/stream' },
      csrfToken: 'token-1',
      credentials: 'include',
    })

    await slot().send('Hello')

    expect(fetch.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('token-1')
    expect(fetch.mock.calls[0][1].credentials).toBe('include')

    wrapper.unmount()
  })

  // The server drops anything outside the turn, so the component offers nothing to send there.
  it('takes no per-turn data', async () => {
    const requests: ChatConnectionRequest[] = []
    const { wrapper, slot } = mountChat(
      { chat: { endpoint: '/chat' }, connection: answering(requests) },
      { data: { persona: 'terse' } },
    )

    await slot().send('Hello')

    expect(Object.keys(requests[0].body)).toEqual(['messages', 'threadId'])

    wrapper.unmount()
  })
})
