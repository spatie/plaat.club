/**
 * The render-prop component, which is `useChat` plus the composer and the scroll anchoring
 * already wired together. Everything it renders comes from those three, so what is worth
 * pinning here is the wiring: that a turn reaches the store, and that the composer and the
 * transcript anchor are handed to the child.
 */

import type * as ChatCore from '@laravel/converse'
import { act, cleanup, render, screen } from '@testing-library/react'
import { createRef } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Chat } from '../src/Chat'
import type { ChatRef, ChatRenderProps } from '../src/types'

const beginTurn = vi.hoisted(() => vi.fn())
const created = vi.hoisted(() => vi.fn<(options: ChatCore.TranscriptScrollOptions) => void>())

// jsdom lays nothing out, so the anchor reserves no room and glides nowhere: the call is the observable.
vi.mock('@laravel/converse', async (importOriginal) => {
  const core = await importOriginal<typeof ChatCore>()

  return {
    ...core,
    createTranscriptScroll: (options: ChatCore.TranscriptScrollOptions): ChatCore.TranscriptScrollController => {
      created(options)

      const scroll = core.createTranscriptScroll(options)

      return {
        attach: scroll.attach,
        beginTurn: (afterRender) => {
          beginTurn()

          return scroll.beginTurn(afterRender)
        },
      }
    },
  }
})

let latest: ChatRenderProps

const holds: ChatCore.ChatConnection = () => new Promise(() => {})

function renderChat(anchorPrompts = true, ref = createRef<ChatRef>()) {
  render(
    <Chat ref={ref} chat={{ endpoint: '/chat' }} connection={holds} anchorPrompts={anchorPrompts}>
      {(props) => {
        latest = props

        return (
          <div>
            <span data-testid="content">{props.messages.map((message) => message.content).join('|')}</span>
            <span data-testid="draft">{props.composer.draft}</span>
            <div data-testid="spacer" style={props.composer.spacerStyle} />
          </div>
        )
      }}
    </Chat>,
  )
}

afterEach(() => {
  cleanup()
  beginTurn.mockClear()
})

describe('the render prop', () => {
  it('hands the child a composer and a transcript anchor', () => {
    renderChat()

    expect(typeof latest.composer.setDraft).toBe('function')
    expect(typeof latest.composer.container).toBe('function')
    expect(screen.getByTestId('spacer').style.height).toBe('0px')
  })

  it('round-trips a draft through the composer', () => {
    renderChat()

    act(() => latest.composer.setDraft('Hello'))

    expect(screen.getByTestId('draft').textContent).toBe('Hello')

    act(() => latest.composer.clear())

    expect(screen.getByTestId('draft').textContent).toBe('')
  })

  it('keeps the composer bindings and the page loader across a render that changes neither', async () => {
    renderChat()

    const { composer, loadPrevious } = latest

    await act(async () => {
      void latest.send('Hello')
    })

    expect(latest.messages).not.toHaveLength(0)
    expect(latest.composer).toBe(composer)
    expect(latest.loadPrevious).toBe(loadPrevious)
  })

  it('passes pagination straight through', () => {
    renderChat()

    expect(latest.hasPrevious).toBe(false)
    expect(typeof latest.loadPrevious).toBe('function')
  })
})

describe('prompt anchoring', () => {
  it('anchors the prompt a sent draft starts', () => {
    renderChat()

    act(() => {
      latest.composer.setDraft('Hello')
      latest.composer.submit()
    })

    expect(beginTurn).toHaveBeenCalledOnce()
  })

  it('leaves the scroll position alone when it is off', () => {
    renderChat(false)

    act(() => {
      latest.composer.setDraft('Hello')
      latest.composer.submit()
    })

    expect(beginTurn).not.toHaveBeenCalled()
  })

  it('anchors at the given offset, and at the latest one after it changes', () => {
    const offset = () => created.mock.lastCall?.[0].offset as () => number | null | undefined

    renderChat()

    expect(offset()()).toBeUndefined()

    cleanup()

    const chat = (anchorOffset: number) => (
      <Chat chat={{ endpoint: '/chat' }} connection={holds} anchorOffset={anchorOffset}>
        {() => null}
      </Chat>
    )

    const { rerender } = render(chat(64))
    const given = offset()

    expect(given()).toBe(64)

    created.mockClear()
    rerender(chat(80))

    expect(created).not.toHaveBeenCalled()
    expect(given()).toBe(80)
  })
})

describe('the ref', () => {
  it('hands the parent the values the render prop gets', () => {
    const ref = createRef<ChatRef>()

    renderChat(true, ref)

    expect(ref.current).toBe(latest)

    act(() => ref.current?.composer.setDraft('From outside'))

    expect(screen.getByTestId('draft').textContent).toBe('From outside')
    expect(ref.current).toBe(latest)
  })
})
