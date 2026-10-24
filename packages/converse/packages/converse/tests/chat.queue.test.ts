/** The turns a busy store holds back, and the order it sends them in. */

import { describe, expect, it, vi } from 'vitest'
import type { AgUiEvent, ChatAttachRequest, ChatMessage } from '../src/types'
import { transport, promptIn, deferred, sourceRef, chatWith, settle } from './chatHarness'

describe('the queue', () => {
  it('sends a message typed mid-run once the run finishes', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async (emit) => {
      await held.promise

      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
      })
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    expect(chat.queued.map((entry) => entry.content)).toEqual(['Second'])

    // On screen while it waits: a prompt that vanishes on submit reads as a
    // failed send, whatever the queue is doing behind it.
    expect(chat.messages.filter((message) => message.role === 'user').map((message) => message.content)).toEqual([
      'First',
      'Second',
    ])

    held.resolve()
    await running

    expect(requests.map(promptIn)).toEqual(['First', 'Second'])
    expect(chat.queued.map((entry) => entry.content)).toEqual([])
  })

  it('names each queued message by the user message it rendered as', async () => {
    const { connection, next } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Again')
    void chat.send('Again')

    const waiting = chat.messages.filter((message) => message.role === 'user').slice(1)

    expect(chat.queued).toEqual(waiting.map((message) => ({ id: message.id, content: 'Again' })))
    expect(new Set(chat.queued.map((entry) => entry.id)).size).toBe(2)

    held.resolve()
    await running
  })

  it('drops on the floor when asked to', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async () => {
      await held.promise
    })

    const chat = chatWith(sourceRef(), { connection, queue: 'drop' })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running

    expect(requests).toHaveLength(1)
    expect(chat.messages.map((m) => m.content)).toEqual(['First', ''])
  })

  it('abandons the answer on screen when asked to interrupt', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await held.promise

      request.signal.throwIfAborted()
    })

    const chat = chatWith(sourceRef(), {
      connection,
      queue: 'interrupt',
    })

    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running
    await settle()

    expect(requests.map(promptIn)).toEqual(['First', 'Second'])
    // The abandoned answer keeps the text that had already arrived.
    expect(chat.messages[1].content).toBe('Half')
    expect(chat.messages[1].status).toBe('complete')
  })

  /*
   * The run an interrupt starts is drained from inside the aborted run's failure path, so the two overlap:
   * the replacement is on the wire before the run it replaced has finished unwinding. Everything the store
   * knows about "the run on the wire" has to survive that, or the second interrupt aborts nothing, the
   * answer stops being copied out, and `attach()` believes the line is free.
   */
  it('still owns the run an interrupt started once the interrupted one unwinds', async () => {
    const { connection, next, requests } = transport()
    const first = deferred()
    const second = deferred()

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await first.promise

      request.signal.throwIfAborted()
    })

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm2',
        delta: 'Also half',
      })

      await second.promise

      request.signal.throwIfAborted()
    })

    const chat = chatWith(sourceRef(), { connection, queue: 'interrupt' })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    first.resolve()
    await running
    await settle()

    // The replacement is on the wire, and the interrupted run has finished unwinding.
    expect(requests).toHaveLength(2)

    void chat.send('Third')

    expect(requests[1].signal.aborted).toBe(true)

    second.resolve()
    await settle()
    await settle()
    await settle()
    await settle()

    expect(requests.map(promptIn)).toEqual(['First', 'Second', 'Third'])
  })

  it('keeps copying the live answer of a run an interrupt started', async () => {
    const { connection, next } = transport()
    const first = deferred()
    const second = deferred()
    let stream: (event: AgUiEvent) => void = () => {}

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await first.promise

      request.signal.throwIfAborted()
    })

    next(async (emit) => {
      stream = emit

      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm2',
        delta: 'One',
      })

      await second.promise
    })

    const chat = chatWith(sourceRef(), { connection, queue: 'interrupt' })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    first.resolve()
    await running
    await settle()

    const before = chat.messages[chat.messages.length - 1]

    stream({
      type: 'TEXT_MESSAGE_CONTENT',
      messageId: 'm2',
      delta: ' two',
    })

    const after = chat.messages[chat.messages.length - 1]

    // The answer is mutated in place, so a memoized message component only hears the rest of it if the
    // store hands out a fresh object each time it changes.
    expect(after.content).toBe('One two')
    expect(after).not.toBe(before)

    second.resolve()
    await settle()
  })

  it('refuses to attach while the run an interrupt started is live', async () => {
    const { connection, next } = transport()
    const attached: ChatAttachRequest[] = []
    const first = deferred()
    const second = deferred()

    connection.attach = async (request) => {
      attached.push(request)
    }

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await first.promise

      request.signal.throwIfAborted()
    })

    next(async () => {
      await second.promise
    })

    const source = sourceRef()
    const chat = chatWith(source, { connection, queue: 'interrupt' })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    first.resolve()
    await running
    await settle()

    // A re-render lands mid-turn naming the run the server is still writing, which is what the other tab
    // and the reload both look like.
    source.current = {
      endpoint: '/chat',
      threadId: 'conv-1',
      offset: 9,
    }

    await chat.store.attach()

    expect(attached).toEqual([])

    second.resolve()
    await settle()
  })

  it('holds the queue back after a failed run', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async () => {
      await held.promise

      throw new Error('The provider fell over.')
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running

    expect(requests).toHaveLength(1)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Second'])
    expect(chat.status).toBe('error')
  })

  it('sends the held queue once the error is dismissed', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async () => {
      await held.promise

      throw new Error('The provider fell over.')
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running

    chat.clearError()
    await settle()

    expect(requests.map(promptIn)).toEqual(['First', 'Second'])
    expect(chat.queued).toEqual([])
  })

  it('sends a message typed after a failed run behind the queue it held', async () => {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async () => {
      await held.promise

      throw new Error('The provider fell over.')
    })

    const chat = chatWith(sourceRef(), { connection })
    const running = chat.send('First')

    await settle()
    void chat.send('Second')

    held.resolve()
    await running

    expect(await chat.send('Third')).toBe(true)

    await vi.waitFor(() => expect(requests.map(promptIn)).toEqual(['First', 'Second', 'Third']))
    expect(chat.error).toBeNull()
  })

  /*
   * Switching threads aborts the run and hands the store back in the same breath, so the next send takes the wire
   * while the run it replaced is still unwinding — an abort is not delivered until the read resumes. The
   * abandoned run still owns the answer it was writing, but the status is the live run's: a run reporting
   * `ready` from there leaves the store looking idle while a stream is arriving, and the next send walks
   * straight past `isBusy()` and opens a request over a run nothing ever aborted.
   */
  it('lets a run abandoned by a thread switch speak for its own message and not for the store', async () => {
    const { connection, next, requests } = transport()
    const abandoned = deferred()
    const live = deferred()

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await abandoned.promise

      request.signal.throwIfAborted()
    })

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm2',
        delta: 'Second',
      })

      await live.promise

      request.signal.throwIfAborted()
    })

    const source = sourceRef()
    const chat = chatWith(source, { connection })

    void chat.send('First')
    await settle()

    source.current = { endpoint: '/chat', threadId: 'conv-2' }
    chat.syncSource()
    void chat.send('Second')
    await settle()

    expect(requests).toHaveLength(2)
    expect(chat.status).toBe('streaming')

    // The abandoned run's read finally notices the abort, two sends later.
    abandoned.resolve()
    await settle()
    await settle()
    await settle()
    await settle()

    expect(chat.messages[chat.messages.length - 1].content).toBe('Second')
    expect(chat.status).toBe('streaming')

    void chat.send('Third')
    await settle()

    // Queued behind the live run rather than sent over the top of it, and the live run is still on the wire.
    expect(requests).toHaveLength(2)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Third'])
    expect(requests[1].signal.aborted).toBe(false)

    live.resolve()
    await settle()
  })

  /*
   * And the third exit: a run that fails outright rather than being aborted. The error is the message's to
   * carry and the store's to show, and an abandoned run showing it there both blocks the composer over a
   * thread nobody is looking at and holds the queue back — `drain()` refuses to run while the store
   * says `error`.
   */
  it('keeps the error of a run abandoned by a thread switch off the store', async () => {
    const { connection, next, requests } = transport()
    const abandoned = deferred()
    const live = deferred()

    next(async () => {
      await abandoned.promise

      throw new Error('The provider fell over.')
    })

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm2',
        delta: 'Second',
      })

      await live.promise

      request.signal.throwIfAborted()
    })

    const source = sourceRef()
    const chat = chatWith(source, { connection })

    void chat.send('First')
    await settle()

    source.current = { endpoint: '/chat', threadId: 'conv-2' }
    chat.syncSource()
    void chat.send('Second')
    await settle()

    expect(requests).toHaveLength(2)

    abandoned.resolve()
    await settle()
    await settle()
    await settle()
    await settle()

    expect(chat.status).toBe('streaming')
    expect(chat.error).toBeNull()

    void chat.send('Third')
    await settle()

    expect(chat.queued.map((entry) => entry.content)).toEqual(['Third'])

    live.resolve()
    await settle()
  })

  /*
   * The same window, reached by the other exit: a run whose answer had already arrived when the switch aborted
   * it resolves rather than throwing, so it walks the success path instead of the failure one. `ready`,
   * `onFinish` and the drain all belong to whoever holds the wire now.
   */
  it('lets a run that finished after a thread switch speak for its own message and not for the store', async () => {
    const { connection, next, requests } = transport()
    const finished: ChatMessage[] = []
    const abandoned = deferred()
    const live = deferred()

    next(async (emit) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Half',
      })

      await abandoned.promise
    })

    next(async (emit, request) => {
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm2',
        delta: 'Second',
      })

      await live.promise

      request.signal.throwIfAborted()
    })

    const source = sourceRef()
    const chat = chatWith(source, {
      connection,
      onFinish: (message) => finished.push(message),
    })

    void chat.send('First')
    await settle()

    source.current = { endpoint: '/chat', threadId: 'conv-2' }
    chat.syncSource()
    void chat.send('Second')
    await settle()

    expect(requests).toHaveLength(2)

    // The abandoned run's request had already answered, so it returns instead of throwing.
    abandoned.resolve()
    await settle()
    await settle()
    await settle()
    await settle()

    expect(chat.status).toBe('streaming')
    expect(finished).toEqual([])

    void chat.send('Third')
    await settle()

    expect(requests).toHaveLength(2)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['Third'])
    expect(requests[1].signal.aborted).toBe(false)

    live.resolve()
    await settle()
  })
})
