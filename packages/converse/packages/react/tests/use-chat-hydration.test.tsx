/**
 * The server snapshot is `getState()` itself, which is only safe while the first client render builds the same
 * state from the same payload. This hydrates real server markup to hold it to that.
 */

import type { ChatConnection, ChatSource, ChatStatus } from '@laravel/converse'
import { act } from '@testing-library/react'
import { hydrateRoot, type Root } from 'react-dom/client'
import { renderToString } from 'react-dom/server'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useChat } from '../src/hooks/use-chat'

const holds: ChatConnection = () => new Promise(() => {})

holds.attach = () => new Promise(() => {})

function Page({ source }: { source: ChatSource }) {
  const chat = useChat(source, { connection: holds })

  return (
    <div>
      <span>{chat.status}</span>
      <span>{chat.messages.map((message) => message.content).join('|')}</span>
      <span>{String(chat.session?.offset ?? null)}</span>
    </div>
  )
}

let root: Root | null = null

afterEach(() => {
  act(() => root?.unmount())
  root = null
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

describe('hydrating', () => {
  it.each([
    ['a fresh chat', { endpoint: '/chat' }, 'ready'],
    [
      'a stored transcript',
      { endpoint: '/chat', threadId: 'conv-1', messages: [{ id: 'm-1', role: 'assistant', content: 'Stored.' }] },
      'ready',
    ],
    ['a thread mid-answer', { endpoint: '/chat', threadId: 'conv-1', offset: 12 }, 'resuming'],
  ] satisfies [string, ChatSource, ChatStatus][])('matches the server markup for %s', async (_name, source, status) => {
    const container = document.createElement('div')
    const recoverable = vi.fn()
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {})

    container.innerHTML = renderToString(<Page source={source} />)
    document.body.appendChild(container)

    const markup = container.innerHTML

    await act(async () => {
      root = hydrateRoot(container, <Page source={source} />, { onRecoverableError: recoverable })
    })

    expect(recoverable).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
    expect(markup).toContain(status)
  })
})
