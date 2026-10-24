/**
 * The composer's rules, stated as tests.
 */

import { describe, expect, it, vi } from 'vitest'
import type { ChatState, ChatStore } from '../src/chat'
import { createChatComposer } from '../src/composer'

function chatStore(overrides: Partial<ChatState> = {}) {
  const sent: string[] = []
  const state = { streaming: false, busy: false, ...overrides } as ChatState
  const chat = {
    getState: () => state,
    send: async (message: string) => {
      sent.push(message)
      state.streaming = true
      state.busy = true

      return true
    },
  } as unknown as ChatStore

  return { chat, sent, state }
}

describe('submit', () => {
  it('sends the draft and empties it', () => {
    const { chat, sent } = chatStore()
    const composer = createChatComposer({ chat })

    composer.setDraft('Hello')
    composer.submit()

    expect(sent).toEqual(['Hello'])
    expect(composer.getDraft()).toBe('')
  })

  it('sends an explicit message without touching the draft', () => {
    const { chat, sent } = chatStore()
    const composer = createChatComposer({ chat })

    composer.setDraft('Typed but not sent')
    composer.submit('A button’s prompt')

    expect(sent).toEqual(['A button’s prompt'])
    expect(composer.getDraft()).toBe('Typed but not sent')
  })

  /*
   * The boundary the test above does not reach: with a different string, "not the draft" and "handed to us"
   * are the same answer. A suggestion chip offering what the reader has already typed tells them apart, and
   * only one of the two leaves the draft alone.
   */
  it('sends an explicit message matching the draft without emptying it', () => {
    const { chat, sent } = chatStore()
    const composer = createChatComposer({ chat })

    composer.setDraft('Same')
    composer.submit('Same')

    expect(sent).toEqual(['Same'])
    expect(composer.getDraft()).toBe('Same')
  })

  it('ignores a blank draft', () => {
    const { chat, sent } = chatStore()
    const composer = createChatComposer({ chat })

    composer.setDraft('   ')
    composer.submit()

    expect(sent).toEqual([])
    expect(composer.getDraft()).toBe('   ')
  })

  it('anchors the prompt it starts a run with', () => {
    const beginTurn = vi.fn()
    const { chat } = chatStore()

    createChatComposer({ chat, beginTurn }).submit('First')

    expect(beginTurn).toHaveBeenCalledOnce()
  })

  /*
   * `beginTurn()` reserves a viewport of room under the newest prompt. For a
   * turn that is only waiting, that scrolls away from the answer still
   * streaming and leaves the new prompt against an empty screen.
   */
  it('does not anchor a turn that is only queued', () => {
    const beginTurn = vi.fn()
    const { chat } = chatStore({ streaming: true, busy: true })

    createChatComposer({ chat, beginTurn }).submit('Second')

    expect(beginTurn).not.toHaveBeenCalled()
  })

  it('does not anchor a turn queued behind a catch-up', () => {
    const beginTurn = vi.fn()
    const { chat } = chatStore({ status: 'resuming', streaming: false, busy: true })

    createChatComposer({ chat, beginTurn }).submit('Second')

    expect(beginTurn).not.toHaveBeenCalled()
  })

  /*
   * `send()` starts the run, so a composer that asked afterwards would decide
   * every turn was queued and never anchor at all.
   */
  it('decides on the state before the send, not after', () => {
    const beginTurn = vi.fn()
    const { chat, state } = chatStore()

    const composer = createChatComposer({ chat, beginTurn })

    composer.submit('First')
    expect(beginTurn).toHaveBeenCalledOnce()
    expect(state.streaming).toBe(true)

    composer.submit('Second')
    expect(beginTurn).toHaveBeenCalledOnce()
  })
})

describe('the draft', () => {
  it('notifies subscribers when it changes', () => {
    const { chat } = chatStore()
    const composer = createChatComposer({ chat })
    const listener = vi.fn()

    composer.subscribe(listener)

    composer.setDraft('Typing')
    expect(listener).toHaveBeenCalledOnce()

    // Unchanged: a keystroke that changes nothing should not re-render.
    composer.setDraft('Typing')
    expect(listener).toHaveBeenCalledOnce()
  })
})

describe('a draft the store did not keep', () => {
  it('hands a draft the store did not keep back to the reader', async () => {
    const chat = {
      getState: () => ({ busy: true }) as ChatState,
      send: async () => false,
    } as unknown as ChatStore

    const composer = createChatComposer({ chat })

    composer.setDraft('Dropped')
    composer.submit()

    expect(composer.getDraft()).toBe('')

    await Promise.resolve()

    expect(composer.getDraft()).toBe('Dropped')
  })

  it('leaves a draft the reader started meanwhile alone', async () => {
    const chat = {
      getState: () => ({ busy: true }) as ChatState,
      send: async () => false,
    } as unknown as ChatStore

    const composer = createChatComposer({ chat })

    composer.setDraft('Dropped')
    composer.submit()
    composer.setDraft('Newer')

    await Promise.resolve()

    expect(composer.getDraft()).toBe('Newer')
  })

  it('never restores a message it was handed rather than typed', async () => {
    const chat = {
      getState: () => ({ busy: true }) as ChatState,
      send: async () => false,
    } as unknown as ChatStore

    const composer = createChatComposer({ chat })

    composer.submit('Explicit')

    await Promise.resolve()

    expect(composer.getDraft()).toBe('')
  })
})
