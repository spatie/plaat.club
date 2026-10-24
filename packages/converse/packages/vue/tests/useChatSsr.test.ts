/**
 * What `useChat` is allowed to do inside a server render.
 *
 * The composable body is `setup()`, which runs in both environments, so every side effect in it is
 * reachable from the server process. The one that matters is the mount-time catch-up: a server-rendered
 * payload carrying an `offset` is precisely what puts a session on the store, so the SSR pass takes that
 * branch on exactly the pages the feature was built for.
 *
 * This file runs in the default node environment on purpose — no `jsdom`, no `window`. Stubbing a server
 * would prove nothing; the absence of a DOM is the condition under test.
 */

import type { ChatAttachRequest, ChatConnection } from '@laravel/converse'
import { describe, expect, it } from 'vitest'
import { defineComponent, h, createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import Chat from '../src/Chat.vue'
import { useChat } from '../src/composables/useChat'

/** A connection that can catch up, recording every thread it was asked to read. */
function attachable(attached: ChatAttachRequest[]): ChatConnection {
  const connection: ChatConnection = async () => {}

  connection.attach = async (request) => {
    attached.push(request)
  }

  return connection
}

/** A page the server rendered mid-answer: the payload names the offset to follow the thread from. */
function liveSource() {
  return {
    endpoint: '/chat',
    threadId: 'conv-1',
    offset: 12,
  }
}

describe('a server render', () => {
  it('does not read the thread it was told about', async () => {
    const attached: ChatAttachRequest[] = []

    const Page = defineComponent({
      setup() {
        const chat = useChat(liveSource(), {
          connection: attachable(attached),
        })

        return () => h('div', chat.status.value)
      },
    })

    await renderToString(createSSRApp(Page))

    // The browser has not arrived yet. A `fetch` from here goes to a relative URL with none of the
    // reader's cookies, and every frame it reads is read on behalf of nobody.
    expect(attached).toEqual([])
  })

  it('still ships the markup the client needs to take over', async () => {
    const attached: ChatAttachRequest[] = []

    const Page = defineComponent({
      setup() {
        const chat = useChat(liveSource(), {
          connection: attachable(attached),
        })

        return () => h('div', chat.status.value)
      },
    })

    // `resuming`, not `ready`: the store decides that when it is made, and the composer stays disabled on
    // the strength of it until the client's catch-up finishes. Moving the guard into `onMounted` would
    // render this `ready` and flash an enabled composer over a run still being read.
    await expect(renderToString(createSSRApp(Page))).resolves.toBe('<div>resuming</div>')
  })

  it('leaves the session on the store for the client to pick up', async () => {
    const attached: ChatAttachRequest[] = []
    let session: unknown = 'unset'

    const Page = defineComponent({
      setup() {
        const chat = useChat(liveSource(), {
          connection: attachable(attached),
        })

        session = chat.session.value

        return () => h('div')
      },
    })

    await renderToString(createSSRApp(Page))

    // Declined, not discarded: hydration runs the same `setup()` with a `window`, and this is what it
    // reads to decide it has a thread to follow.
    expect(session).toEqual({ threadId: 'conv-1', offset: 12 })
  })

  it('renders the component without reading the thread or touching the DOM', async () => {
    const attached: ChatAttachRequest[] = []

    const Page = defineComponent({
      setup() {
        return () =>
          h(
            Chat,
            { chat: liveSource(), connection: attachable(attached) },
            {
              default: ({ status, composer }: { status: string; composer: { spacerStyle: { height: string } } }) =>
                h('div', { style: composer.spacerStyle }, status),
            },
          )
      },
    })

    await expect(renderToString(createSSRApp(Page))).resolves.toBe(
      '<!--[--><div style="height:0px;flex:none;">resuming</div><!--]-->',
    )
    expect(attached).toEqual([])
  })
})
