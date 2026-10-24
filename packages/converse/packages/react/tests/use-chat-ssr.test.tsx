// @vitest-environment node

/**
 * What `useChat` is allowed to do inside a server render.
 *
 * A payload carrying an `offset` is what puts a session on the store, so a server render of a page with a
 * thread takes the follow branch on exactly the pages the feature was built for. The follow belongs in the
 * browser, which is why it waits for the effect.
 *
 * This file runs in the node environment on purpose — no `jsdom`, no `window`. The absence of a DOM is the
 * condition under test.
 */

import type { ChatAttachRequest, ChatConnection, ChatSession } from '@laravel/converse'
import { renderToString } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { useChat } from '../src/hooks/use-chat'

/** A connection that can follow a thread, recording every thread it was asked to read. */
function attachable(attached: ChatAttachRequest[]): ChatConnection {
  const connection: ChatConnection = async () => {}

  connection.attach = async (request) => {
    attached.push(request)
  }

  return connection
}

/** A page the server rendered mid-answer: the payload names the offset to follow the thread from. */
const liveSource = { endpoint: '/chat', threadId: 'conv-1', offset: 12 }

describe('a server render', () => {
  it('ships the status and the session, and reads nothing', () => {
    const attached: ChatAttachRequest[] = []
    let session: ChatSession | null = null

    function Page() {
      const chat = useChat(liveSource, { connection: attachable(attached) })

      session = chat.session

      return <div>{chat.status}</div>
    }

    // `resuming`, not `ready`: the composer stays disabled until the client's catch-up finishes.
    expect(renderToString(<Page />)).toBe('<div>resuming</div>')
    expect(session).toEqual({ threadId: 'conv-1', offset: 12 })
    expect(attached).toEqual([])
  })
})
