/** The merge between a live turn and the transcript the page carries. */

import { describe, expect, it } from 'vitest'
import type { ChatMessage } from '../src/types'
import {
  transport,
  deferred,
  payloadMessage,
  pausedPayloadMessage,
  saveNoteInterrupt,
  attachable,
  emitFrames,
  type Payload,
  sourceRef,
  unreachable,
  chatWith,
  settle,
  changes,
  resumes,
  transcriptOf,
} from './chatHarness'

describe('the transcript', () => {
  it('renders an older page Inertia merged into the paged transcript above the newest', () => {
    const source = sourceRef({
      transcriptPath: 'chat.messages',
      messages: { data: [payloadMessage({ id: 'newest' }), payloadMessage({ id: 'newer' })] },
    })
    const chat = chatWith(source)
    const newest = chat.messages[1]

    source.current = {
      ...source.current,
      messages: {
        data: [
          payloadMessage({ id: 'newest' }),
          payloadMessage({ id: 'newer' }),
          payloadMessage({ id: 'older' }),
          payloadMessage({ id: 'oldest' }),
        ],
      },
    }

    expect(chat.messages.map((message) => message.id)).toEqual(['oldest', 'older', 'newer', 'newest'])
    expect(chat.messages[3]).toEqual(newest)
  })

  it('renders a message a page merged in twice only once', () => {
    const chat = chatWith(
      sourceRef({
        messages: {
          data: [payloadMessage({ id: 'newest' }), payloadMessage({ id: 'older' }), payloadMessage({ id: 'newest' })],
        },
      }),
    )

    expect(chat.messages.map((message) => message.id)).toEqual(['older', 'newest'])
  })

  it('keeps the turn this browser streamed below an older page merged in', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'TEXT_MESSAGE_START', messageId: 'm-1', role: 'assistant' })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'm-1', delta: 'Live answer.' })
      emit({ type: 'TEXT_MESSAGE_END', messageId: 'm-1' })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
        messageId: 'stored-answer',
        userMessageId: 'stored-prompt',
      })
    })

    const source = sourceRef({
      threadId: 'conv-1',
      transcriptPath: 'chat.messages',
      messages: { data: [payloadMessage({ id: 'newest' })] },
    })
    const chat = chatWith(source, { connection })

    await chat.send('Live prompt')

    source.current = {
      ...source.current,
      messages: { data: [payloadMessage({ id: 'newest' }), payloadMessage({ id: 'older', role: 'user' })] },
    }

    expect(chat.messages.map((message) => message.id)).toEqual(['older', 'newest', 'stored-prompt', 'stored-answer'])
    expect(chat.messages[3].content).toBe('Live answer.')
  })

  it('keeps the answer on a paused message while an older page merges in', async () => {
    const { connection, next } = transport()

    next(resumes({ messageId: 'stored-1' }))

    const source = sourceRef({
      threadId: 'conv-1',
      transcriptPath: 'chat.messages',
      messages: { data: [{ ...pausedPayloadMessage(), content: '' }] },
      interrupts: [saveNoteInterrupt],
    })
    const chat = chatWith(source, { connection })

    await chat.approve('call-1')

    source.current = {
      ...source.current,
      messages: {
        data: [
          { ...pausedPayloadMessage(), content: '' },
          payloadMessage({ id: 'older', role: 'user', content: 'Hi' }),
        ],
      },
    }

    expect(transcriptOf(chat.messages)).toEqual([
      ['user', 'Hi'],
      ['assistant', 'Saved!'],
    ])
    expect(chat.messages[1].toolCalls).toEqual([
      expect.objectContaining({ id: 'call-1', result: 'Saved notes.txt.', state: 'complete' }),
    ])
  })

  it('keeps the older pages above a snapshot that replaces the newest', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-9' },
        {
          type: 'MESSAGES_SNAPSHOT',
          messages: [payloadMessage({ id: 'newer' }), payloadMessage({ id: 'newest' })],
        },
        { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-9' },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
        transcriptPath: 'chat.messages',
        messages: { data: [payloadMessage({ id: 'newer' }), payloadMessage({ id: 'older' })] },
      }),
      { connection },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => message.id)).toEqual(['older', 'newer', 'newest'])
  })

  it('drops the older pages under a snapshot they never meet', async () => {
    const connection = attachable((request) => {
      emitFrames(request, [
        { type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-9' },
        { type: 'MESSAGES_SNAPSHOT', messages: [payloadMessage({ id: 'much-newer' })] },
        { type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-9' },
      ])
    })

    const chat = chatWith(
      sourceRef({
        threadId: 'conv-1',
        offset: 0,
        transcriptPath: 'chat.messages',
        messages: { data: [payloadMessage({ id: 'older' })] },
      }),
      { connection },
    )

    await chat.store.attach()

    expect(chat.messages.map((message) => message.id)).toEqual(['much-newer'])
  })

  it('flattens and reverses a paged transcript', () => {
    const chat = chatWith(
      sourceRef({
        messages: {
          data: [payloadMessage({ id: 'newest' }), payloadMessage({ id: 'oldest' })],
        },
      }),
    )

    expect(chat.messages.map((message) => message.id)).toEqual(['oldest', 'newest'])
  })

  it('renders the approvals the payload says the last run paused on', () => {
    const chat = chatWith(
      sourceRef({
        messages: [payloadMessage({ id: 'stored-user', role: 'user', content: 'Save it.' }), pausedPayloadMessage()],
        interrupts: [saveNoteInterrupt],
        capabilities: { approvals: true },
      }),
    )

    expect(chat.messages[1].status).toBe('awaiting_approval')
    expect(chat.messages[1].toolCalls[0]).toMatchObject({ id: 'call-1', state: 'awaiting_approval' })
    expect(chat.pendingApprovals).toEqual([
      { id: 'call-1', tool: 'SaveNote', arguments: { id: 7 }, reason: 'Save this?' },
    ])
    expect(chat.can.approve).toBe(true)
  })

  it('renders a stored tool result on the call that asked for it', () => {
    const chat = chatWith(
      sourceRef({
        messages: [pausedPayloadMessage(), { id: 'result-1', role: 'tool', toolCallId: 'call-1', content: 'Saved.' }],
      }),
    )

    expect(chat.messages).toHaveLength(1)
    expect(chat.messages[0].toolCalls[0]).toMatchObject({ result: 'Saved.', state: 'complete', error: null })
  })

  it('renders a stored answer’s web search, citations and usage the way they streamed', async () => {
    const search = {
      provider: 'anthropic',
      itemId: 'srvtoolu_1',
      type: 'server_tool_use',
      data: {},
      status: 'completed',
    }
    const results = { ...search, type: 'web_search_tool_result', status: 'result_received' }
    const docs = { url: 'https://laravel.com/docs/13.x', title: 'Laravel 13' }
    const news = { url: 'https://laravel-news.com' }
    const usage = {
      provider: 'anthropic',
      model: 'claude-opus-5-5',
      inputTokens: 120,
      outputTokens: 30,
      totalTokens: 150,
    }
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({ type: 'STEP_STARTED', stepName: '1' })
      emit({ type: 'CUSTOM', name: 'provider-tool', value: search })
      emit({ type: 'CUSTOM', name: 'provider-tool', value: results })
      emit({ type: 'TEXT_MESSAGE_CONTENT', messageId: 'text-1', delta: 'Laravel 13 is out.' })
      emit({ type: 'CUSTOM', name: 'citation', value: docs })
      emit({ type: 'CUSTOM', name: 'citation', value: news })
      emit({ type: 'CUSTOM', name: 'citation', value: docs })
      emit({ type: 'RUN_FINISHED', threadId: 'conv-1', runId: 'run-1', usage: [usage] })
    })

    const live = chatWith(sourceRef(), { connection })

    await live.send('What is new?')

    const reloaded = chatWith(
      sourceRef({
        messages: [
          payloadMessage({
            content: 'Laravel 13 is out.',
            usage,
            parts: [
              { type: 'custom', name: 'provider-tool', value: search },
              { type: 'custom', name: 'provider-tool', value: results },
              { type: 'text', text: 'Laravel 13 is out.' },
              { type: 'custom', name: 'citation', value: docs },
              { type: 'custom', name: 'citation', value: news },
              { type: 'custom', name: 'citation', value: docs },
            ],
          }),
        ],
      }),
    )

    const pick = (message: ChatMessage) => ({
      parts: message.parts,
      citations: message.citations,
      usage: message.usage,
      provider: message.provider,
      model: message.model,
    })

    expect(pick(reloaded.messages[0])).toEqual(pick(live.messages[1]))
    expect(reloaded.messages[0].citations).toEqual([
      { title: 'Laravel 13', url: 'https://laravel.com/docs/13.x' },
      { title: null, url: 'https://laravel-news.com' },
    ])
    expect(reloaded.messages[0].parts.map((part) => part.type)).toEqual([
      'data-provider-tool',
      'data-provider-tool',
      'text',
      'source-url',
      'source-url',
      'source-url',
    ])
    expect(reloaded.messages[0]).toMatchObject({
      provider: 'anthropic',
      model: 'claude-opus-5-5',
      usage: { totalTokens: 150 },
    })
  })

  it('keeps the rendered messages while the payload has not moved', () => {
    const chat = chatWith(sourceRef({ messages: [payloadMessage()] }))

    const first = chat.store.getState().messages[0]

    chat.clearError()

    expect(chat.store.getState().messages[0]).toBe(first)
  })

  it('reads history through, so a prepended page moves the screen', () => {
    const source = sourceRef({ messages: [payloadMessage({ id: 'b' })] })
    const chat = chatWith(source)

    source.current = {
      ...source.current,
      messages: [payloadMessage({ id: 'a' }), payloadMessage({ id: 'b' })],
    }

    expect(chat.messages.map((message) => message.id)).toEqual(['a', 'b'])
  })

  it('retires a live turn once the server transcript carries its id', async () => {
    const { connection, next } = transport()

    next((emit) => {
      emit({ type: 'RUN_STARTED', threadId: 'conv-1', runId: 'run-1' })
      emit({
        type: 'TEXT_MESSAGE_CONTENT',
        messageId: 'm1',
        delta: 'Hi',
      })
      emit({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
        messageId: 'stored-answer',
      })
    })

    const source = sourceRef()
    const chat = chatWith(source, { connection })

    await chat.send('Hello')

    // Still live: the server transcript has not caught up yet.
    expect(chat.messages).toHaveLength(2)
    expect(chat.messages[1].content).toBe('Hi')

    source.current = {
      ...source.current,
      messages: [payloadMessage({ id: 'stored-prompt', role: 'user' }), payloadMessage({ id: 'stored-answer' })],
    }

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-prompt', 'stored-answer'])
  })

  it('drops every local trace when the server switches thread', async () => {
    const { connection } = transport()
    const source = sourceRef({ threadId: 'conv-1' })
    const chat = chatWith(source, { connection })

    await chat.send('Hello')

    source.current = { ...source.current, threadId: 'conv-2' }
    chat.syncSource()
    await settle()

    expect(chat.messages).toEqual([])
    expect(chat.threadId).toBe('conv-2')
  })
})

describe('syncSource', () => {
  /** A run held open, so the thread can be switched out from under it. */
  function running(overrides: Parameters<typeof sourceRef>[0] = {}, reports?: string) {
    const { connection, next, requests } = transport()
    const held = deferred()

    next(async (emit, request) => {
      emit({
        type: 'RUN_STARTED',
        threadId: reports ?? (request.body.threadId as string),
        runId: 'run-1',
      })

      await held.promise
    })

    const payload = sourceRef(overrides)
    const chat = chatWith(payload, { connection })

    return { chat, payload, requests, held }
  }

  function name(payload: Payload, threadId: string | null): void {
    payload.current = { ...payload.current, threadId }
  }

  it('does nothing when the payload names the same thread', async () => {
    const { chat, payload } = running({ threadId: 'conv-1' })

    void chat.send('Hello')
    await settle()

    const heard = changes(chat.store)

    chat.syncSource()

    expect(heard()).toBe(0)
    expect(chat.threadId).toBe('conv-1')
    expect(chat.messages).toHaveLength(2)

    name(payload, 'conv-1')
    chat.syncSource()

    expect(heard()).toBe(0)
  })

  it('cancels a run that is still in flight', async () => {
    const { chat, payload, requests } = running({ threadId: 'conv-1' })

    void chat.send('Hello')
    await settle()

    expect(requests[0].signal.aborted).toBe(false)

    name(payload, 'conv-2')
    chat.syncSource()

    expect(requests[0].signal.aborted).toBe(true)
  })

  it('drops the transcript and the queue the old thread owned', async () => {
    const { chat, payload } = running({ threadId: 'conv-1' })

    void chat.send('Hello')
    await settle()
    await chat.send('And this')

    expect(chat.messages).not.toHaveLength(0)
    expect(chat.queued.map((entry) => entry.content)).toEqual(['And this'])

    name(payload, 'conv-2')
    chat.syncSource()

    expect(chat.messages).toEqual([])
    expect(chat.queued.map((entry) => entry.content)).toEqual([])
    expect(chat.status).toBe('ready')
    expect(chat.threadId).toBe('conv-2')
  })

  it('treats a payload that names no thread as a switch', async () => {
    const { chat, payload } = running({ threadId: 'conv-1' })

    void chat.send('Hello')
    await settle()

    const heard = changes(chat.store)

    name(payload, null)
    chat.syncSource()

    expect(heard()).toBe(1)
    expect(chat.threadId).toBeNull()
    expect(chat.messages).toEqual([])
  })

  /*
   * The client learns the id from `RUN_STARTED` before the payload catches up,
   * so the payload arriving with that same id is not a switch. It is recorded
   * and nothing else, or the turn just streamed would be wiped.
   */
  it('adopts an id the run already reported without dropping the turn', async () => {
    const { chat, payload } = running({}, 'conv-9')

    void chat.send('Hello')
    await settle()

    expect(chat.threadId).toBe('conv-9')

    name(payload, 'conv-9')
    const heard = changes(chat.store)

    chat.syncSource()

    expect(heard()).toBe(0)
    expect(chat.threadId).toBe('conv-9')
    expect(chat.messages).toHaveLength(2)
  })

  /*
   * Called again with an unchanged payload, which is what an adapter watching a
   * prop by identity rather than by value does. Nothing has moved, so the run
   * in flight is left alone.
   */
  it('leaves a live run alone when the payload has not moved', async () => {
    const { chat } = running({}, 'conv-9')

    void chat.send('Hello')
    await settle()

    expect(chat.threadId).toBe('conv-9')

    const heard = changes(chat.store)

    chat.syncSource()

    expect(heard()).toBe(0)
    expect(chat.threadId).toBe('conv-9')
    expect(chat.messages).toHaveLength(2)
  })
})

describe('a history getter that answers nothing', () => {
  it("leaves the payload's transcript in charge", () => {
    const chat = chatWith(sourceRef({ messages: [payloadMessage()] }), {
      connection: unreachable,
      history: () => undefined,
    })

    expect(chat.messages.map((message) => message.id)).toEqual(['stored-1'])
  })
})
