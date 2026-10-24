/**
 * The draft lives in the core composer, not in the adapter.
 *
 * `composer.test.ts` in core covers what a draft does; this covers the one thing React adds:
 * subscribing to the composer so typing re-renders, rather than mirroring the draft in state.
 */

import { createChat, type ChatConnection, type ChatStore } from '@laravel/converse'
import { act, cleanup, render, screen } from '@testing-library/react'
import { Suspense, startTransition, useLayoutEffect, useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useChatComposer } from '../src/hooks/use-chat-composer'

const neverResolves: ChatConnection = () => new Promise(() => {})

let latest: ReturnType<typeof useChatComposer>

function Harness({ chat }: { chat: ChatStore }) {
  const composer = useChatComposer(chat)

  latest = composer

  return <span data-testid="draft">{composer.draft}</span>
}

type PendingPage = { beginTurn: () => void; waiting: boolean }

const neverLoads = new Promise<never>(() => {})

let setPendingPage: (page: PendingPage) => void

/** Suspends for as long as the page it belongs to is still loading. */
function Loading({ waiting }: { waiting: boolean }) {
  if (waiting) {
    throw neverLoads
  }

  return null
}

function TransitionHarness({ chat, initial }: { chat: ChatStore; initial: PendingPage }) {
  const [page, setPage] = useState(initial)

  setPendingPage = setPage
  latest = useChatComposer(chat, { beginTurn: page.beginTurn })

  return (
    <Suspense fallback="loading">
      <Loading waiting={page.waiting} />
    </Suspense>
  )
}

type SubmittingPage = { beginTurn: () => void; submitOnCommit: boolean }

let setSubmittingPage: (page: SubmittingPage) => void

function SubmitOnCommit({ submit, submitOnCommit }: { submit: (message: string) => unknown; submitOnCommit: boolean }) {
  useLayoutEffect(() => {
    if (submitOnCommit) {
      submit('Hello')
    }
  }, [submit, submitOnCommit])

  return null
}

function SubmittingHarness({ chat, initial }: { chat: ChatStore; initial: SubmittingPage }) {
  const [page, setPage] = useState(initial)
  const composer = useChatComposer(chat, { beginTurn: page.beginTurn })

  setSubmittingPage = setPage

  return <SubmitOnCommit submit={composer.submit} submitOnCommit={page.submitOnCommit} />
}

function renderComposer() {
  const chat = createChat({
    source: () => ({ endpoint: '/chat' }),
    connection: neverResolves,
  })

  render(<Harness chat={chat} />)

  return chat
}

afterEach(cleanup)

describe('the draft', () => {
  it('re-renders as it is typed', () => {
    renderComposer()

    act(() => latest.setDraft('Half a thought'))

    expect(screen.getByTestId('draft').textContent).toBe('Half a thought')
  })

  it('reads the composer rather than a mirror of it, so clearing is visible', () => {
    renderComposer()

    act(() => latest.setDraft('Sent'))
    act(() => latest.clear())

    expect(screen.getByTestId('draft').textContent).toBe('')
  })

  it('keeps one composer across re-renders', () => {
    renderComposer()

    const first = latest.composer

    act(() => latest.setDraft('Typed'))

    expect(latest.composer).toBe(first)
  })
})

describe('beginning a turn', () => {
  it('runs the callback on screen, not one from a render that has not committed', async () => {
    const chat = createChat({
      source: () => ({ endpoint: '/chat' }),
      connection: neverResolves,
    })
    const shown = vi.fn()
    const loading = vi.fn()

    render(<TransitionHarness chat={chat} initial={{ beginTurn: shown, waiting: false }} />)

    await act(async () => startTransition(() => setPendingPage({ beginTurn: loading, waiting: true })))

    act(() => latest.submit('Hello'))

    expect(shown).toHaveBeenCalledOnce()
    expect(loading).not.toHaveBeenCalled()
  })

  it('runs the committed callback when a child submits from its own layout effect', () => {
    const chat = createChat({
      source: () => ({ endpoint: '/chat' }),
      connection: neverResolves,
    })
    const before = vi.fn()
    const after = vi.fn()

    render(<SubmittingHarness chat={chat} initial={{ beginTurn: before, submitOnCommit: false }} />)

    act(() => setSubmittingPage({ beginTurn: after, submitOnCommit: true }))

    expect(after).toHaveBeenCalledOnce()
    expect(before).not.toHaveBeenCalled()
  })
})
