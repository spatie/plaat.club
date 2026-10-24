/** What the markup is told, who hears about a change, and mounting. */

import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ChatAttachRequest, ChatMessage } from '../src/types'
import {
  transport,
  deferred,
  storedMessage,
  payloadMessage,
  attachable,
  sourceRef,
  chatWith,
  settle,
} from './chatHarness'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('abilities', () => {
  it('reads a capability as present, never as false', () => {
    const chat = chatWith(sourceRef({ capabilities: { approvals: true, streaming: false } }))

    expect(chat.can).toEqual({
      approve: true,
      stream: false,
      retry: false,
    })
  })

  it('assumes streaming when the server said nothing at all', () => {
    const chat = chatWith(sourceRef())

    expect(chat.can.stream).toBe(true)
    expect(chat.can.approve).toBe(false)
  })

  /*
   * A handler or command bot stores the prompt before it answers, so a replay of it is refused as
   * a duplicate. The server only offers the capability where a retry can land.
   */
  it('offers a retry only where the server supports one', () => {
    const unanswered = [payloadMessage({ id: 'stored-user', role: 'user', content: 'Unanswered' })]

    expect(chatWith(sourceRef({ threadId: 'conv-1', messages: unanswered })).can.retry).toBe(false)
    expect(chatWith(sourceRef({ threadId: 'conv-1', messages: unanswered, capabilities: {} })).can.retry).toBe(false)
    expect(
      chatWith(sourceRef({ threadId: 'conv-1', messages: unanswered, capabilities: { retry: true } })).can.retry,
    ).toBe(true)
  })

  it('builds infinite scroll props only for a paged transcript', () => {
    expect(chatWith(sourceRef()).infiniteScrollProps).toBeNull()

    expect(chatWith(sourceRef({ transcriptPath: 'chat.messages' })).infiniteScrollProps).toEqual({
      data: 'chat.messages',
      reverse: true,
      buffer: 250,
      preserveUrl: true,
    })
  })
})

describe('callbacks', () => {
  it('reports every event and the finished message', async () => {
    const { connection } = transport()
    const seen: string[] = []
    const finished: ChatMessage[] = []

    const chat = chatWith(sourceRef(), {
      connection,
      onEvent: (event) => seen.push(event.type),
      onFinish: (message) => finished.push(message),
    })

    await chat.send('Hello')

    expect(seen).toEqual(['TEXT_MESSAGE_CONTENT', 'RUN_FINISHED'])
    expect(finished).toHaveLength(1)
    expect(finished[0].content).toBe('Hi')
  })
})

describe('the snapshot', () => {
  /*
   * `getState` hands back the same object while nothing it was built from has
   * moved, so a `useSyncExternalStore` adapter can trust its identity. History
   * is supplied by a getter of its own, so replacing it has to invalidate as
   * surely as a new payload does.
   */
  it('changes identity when a history getter hands back a different transcript', () => {
    const { connection } = transport()
    let history: ChatMessage[] = [storedMessage({ id: 'stored-1' })]

    const chat = chatWith(sourceRef({ threadId: 'conv-1' }), {
      connection,
      history: () => history,
    })
    const before = chat.store.getState()

    expect(chat.store.getState()).toBe(before)
    expect(before.messages).toHaveLength(1)

    history = [storedMessage({ id: 'stored-1' }), storedMessage({ id: 'stored-2' })]

    expect(chat.store.getState()).not.toBe(before)
    expect(chat.store.getState().messages).toHaveLength(2)
  })
})

describe('subscribers', () => {
  it('stop hearing about changes once they unsubscribe', async () => {
    const { connection } = transport()
    const chat = chatWith(sourceRef(), { connection })

    let calls = 0
    const unsubscribe = chat.store.subscribe(() => {
      calls += 1
    })

    await chat.send('Hello')

    const heard = calls

    unsubscribe()
    await chat.send('And this')

    expect(heard).toBeGreaterThan(0)
    expect(calls).toBe(heard)
  })
})

describe('mounting', () => {
  it('follows the thread from the offset the page named, and stops reading it on teardown', async () => {
    vi.stubGlobal('window', {})

    const held = deferred()
    const attached: ChatAttachRequest[] = []
    const connection = attachable(async (request) => {
      attached.push(request)

      await held.promise
    })

    const chat = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), {
      connection,
    })

    const unmount = chat.store.mount()

    await settle()
    unmount()

    expect(attached).toHaveLength(1)
    expect(attached[0].signal.aborted).toBe(true)

    held.resolve()
  })

  it('reads nothing where the page named no offset, or on the server', async () => {
    const connection = attachable(() => {
      throw new Error('Nothing should have been read.')
    })

    const named = chatWith(sourceRef({ threadId: 'conv-1', offset: 3 }), {
      connection,
    })

    named.store.mount()

    vi.stubGlobal('window', {})

    const unnamed = chatWith(sourceRef({ threadId: 'conv-1' }), { connection })

    unnamed.store.mount()()

    expect(named.status).toBe('resuming')
    expect(unnamed.status).toBe('ready')
  })
})
