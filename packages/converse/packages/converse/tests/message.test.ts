/**
 * How a stream becomes one assistant message, stated as tests.
 *
 * Every case here encodes a behavior of laravel/ai that the client would
 * otherwise have to re-derive by reading the SDK — a run of deltas is a
 * paragraph, a multi-step answer joins with a blank line, an error frame cannot
 * be recognized by its `type`. They are the executable half of the argument that
 * this file belongs upstream: a PHP serializer producing the same shape has to
 * agree with exactly these.
 */

import { describe, expect, it } from 'vitest'
import {
  applySnapshotApprovals,
  createUserMessage,
  isAwaitingContent,
  messagesFromSnapshot,
  patchToolCalls,
  StreamedMessage,
  transcriptFrom,
} from '../src/message'
import type { AgUiEvent } from '../src/types'

function accumulate(...events: AgUiEvent[]): StreamedMessage {
  return events.reduce((accumulator, event) => accumulator.apply(event), new StreamedMessage('assistant-1'))
}

/** A stored assistant message that announced one call. */
function announcing(toolCallId: string) {
  return {
    role: 'assistant' as const,
    content: '',
    toolCalls: [{ id: toolCallId, type: 'function', function: { name: 'SaveNote', arguments: '{}' } }],
  }
}

function base(type: string, extra: Record<string, unknown> = {}) {
  return { type, ...extra } as AgUiEvent
}

function textDelta(messageId: string, delta: string) {
  return base('TEXT_MESSAGE_CONTENT', { messageId, delta })
}

/** A tool call, announced the way AG-UI announces one. */
function toolCall(id: string, name: string, args: Record<string, unknown> = {}) {
  return [
    base('TOOL_CALL_START', { toolCallId: id, toolCallName: name }),
    base('TOOL_CALL_ARGS', { toolCallId: id, delta: JSON.stringify(args) }),
    base('TOOL_CALL_END', { toolCallId: id }),
  ]
}

/** A result, plus the `CUSTOM` frame carrying the outcome AG-UI cannot express. */
function toolResult(
  id: string,
  content: string,
  outcome: {
    successful?: boolean
    denied?: boolean
    error?: string | null
  } = {},
) {
  return [
    base('TOOL_CALL_RESULT', {
      toolCallId: id,
      messageId: 'm',
      content,
      ...(outcome.successful === false
        ? {
            metadata: {
              error: outcome.error ?? content,
              ...(outcome.denied === true ? { denied: true } : {}),
            },
          }
        : {}),
    }),
  ]
}

function runFinished(extra: Record<string, unknown> = {}) {
  return base('RUN_FINISHED', { threadId: 't', runId: 'r', ...extra })
}

describe('text', () => {
  it('concatenates the deltas of one message into one segment', () => {
    const { message } = accumulate(
      base('TEXT_MESSAGE_START', { messageId: 'm1', role: 'assistant' }),
      textDelta('m1', 'Laravel MCP '),
      textDelta('m1', 'builds MCP servers.'),
      base('TEXT_MESSAGE_END', { messageId: 'm1' }),
    )

    expect(message.content).toBe('Laravel MCP builds MCP servers.')
  })

  /*
   * A message that ended is finished. When the same id streams again — a log
   * replayed past the watermark, or a step attempted a second time — the new
   * attempt replaces what the first wrote instead of extending it. AG-UI's
   * `TEXT_MESSAGE_END` carries no content, so the terminal event cannot state
   * the completed text itself; what it can state is that the deltas are done.
   */
  it('replaces a completed message rather than extending it when its id streams again', () => {
    const attempt = [
      base('TEXT_MESSAGE_START', { messageId: 'm1', role: 'assistant' }),
      textDelta('m1', 'Laravel MCP '),
      textDelta('m1', 'builds MCP servers.'),
      base('TEXT_MESSAGE_END', { messageId: 'm1' }),
    ]

    const { message } = accumulate(base('STEP_STARTED', { stepName: '1' }), ...attempt, ...attempt)

    expect(message.content).toBe('Laravel MCP builds MCP servers.')
    expect(message.parts).toEqual([{ type: 'text', text: 'Laravel MCP builds MCP servers.' }])
  })

  // The replacement keeps the step the first attempt occupied, so a step that
  // was replayed does not also open a paragraph of its own.
  it('keeps a replayed step in the paragraph it already rendered', () => {
    const { message } = accumulate(
      textDelta('m1', 'First step.'),
      base('TEXT_MESSAGE_END', { messageId: 'm1' }),
      base('STEP_STARTED', { stepName: '2' }),
      textDelta('m2', 'Second step.'),
      base('TEXT_MESSAGE_END', { messageId: 'm2' }),
      // The reader went back and re-read the whole run.
      textDelta('m1', 'First step.'),
      base('TEXT_MESSAGE_END', { messageId: 'm1' }),
    )

    expect(message.content).toBe('First step.\n\nSecond step.')
    expect(message.parts).toEqual([
      { type: 'text', text: 'First step.' },
      { type: 'text', text: 'Second step.' },
    ])
  })

  // A step abandoned mid-sentence never ends, so nothing marks it finished and
  // the deltas still run together. Only a completed message is authoritative.
  it('still extends a message that never ended', () => {
    const { message } = accumulate(textDelta('m1', 'Laravel MCP '), textDelta('m1', 'builds MCP servers.'))

    expect(message.content).toBe('Laravel MCP builds MCP servers.')
  })

  /*
   * Mirrors `Laravel\Ai\Streaming\Events\TextDelta::combine()`. A multi-step
   * run answers several times, and each step is a self-contained utterance —
   * running them together mid-sentence is what a naive `+=` would do.
   *
   * The boundary is `STEP_STARTED`, not a change of message id: a provider that
   * cites its sources closes the text block at every citation, so one step can
   * hold several ids.
   */
  it('joins the steps of a multi-step answer with a blank line', () => {
    const { message } = accumulate(
      textDelta('m1', 'First step.'),
      base('STEP_STARTED', { stepName: '2' }),
      textDelta('m2', 'Second step.'),
    )

    expect(message.content).toBe('First step.\n\nSecond step.')
  })

  /*
   * What Anthropic's web search actually sends: the text block closes at each
   * citation and reopens with a new id, mid-sentence. Splitting on the id gave
   * ", having been released on…" its own paragraph, and a lone full stop after.
   */
  /*
   * A provider cites the same page at every sentence it supports. The parts
   * keep each mention — that is where it was used — but `citations` is the
   * bibliography, and ten links to one page is not a bibliography.
   */
  it('lists each cited source once, however often it is mentioned', () => {
    const cite = (url: string, title: string) => base('CUSTOM', { name: 'citation', value: { url, title } })

    const { message } = accumulate(
      textDelta('m1', 'Laravel 13 shipped in March'),
      cite('https://releasebot.io', 'Releasebot'),
      cite('https://releasebot.io', 'Releasebot'),
      cite('https://versionlog.com', 'Versionlog'),
      cite('https://releasebot.io', 'Releasebot'),
    )

    expect(message.citations).toEqual([
      { title: 'Releasebot', url: 'https://releasebot.io' },
      { title: 'Versionlog', url: 'https://versionlog.com' },
    ])

    // Every mention survives in `parts`, where position is the point.
    expect(message.parts.filter((part) => part.type === 'source-url')).toHaveLength(4)
  })

  it('keeps one step whole when a provider splits it around a citation', () => {
    const { message } = accumulate(
      textDelta('m1', 'Laravel 13 is current, which'),
      base('CUSTOM', {
        name: 'citation',
        value: { url: 'https://laravel.com', title: 'Laravel' },
      }),
      textDelta('m2', ' shipped in March'),
      base('CUSTOM', {
        name: 'citation',
        value: { url: 'https://laravel.com/docs', title: 'Docs' },
      }),
      textDelta('m3', '.'),
    )

    expect(message.content).toBe('Laravel 13 is current, which shipped in March.')

    expect(message.parts.filter((part) => part.type === 'text')).toEqual([
      {
        type: 'text',
        text: 'Laravel 13 is current, which shipped in March.',
      },
    ])
  })

  it('drops a step that produced no text, rather than leaving a gap', () => {
    const { message } = accumulate(
      textDelta('m1', 'Only step with words.'),
      base('TEXT_MESSAGE_START', { messageId: 'm2', role: 'assistant' }),
      base('TEXT_MESSAGE_END', { messageId: 'm2' }),
    )

    expect(message.content).toBe('Only step with words.')
  })

  /*
   * Some providers never emit `text_start`, so appending has to be able to
   * open a segment on its own. This is the case that breaks if `text_start`
   * is ever treated as required.
   */
  it('opens a segment for a delta that arrives without a start event', () => {
    const { message } = accumulate(textDelta('m1', 'No start event.'))

    expect(message.content).toBe('No start event.')
  })
})

describe('reasoning', () => {
  it('accumulates separately from the answer', () => {
    const { message } = accumulate(
      base('REASONING_MESSAGE_START', {
        messageId: 'r1',
        role: 'reasoning',
      }),
      base('REASONING_MESSAGE_CONTENT', {
        messageId: 'r1',
        delta: 'Thinking…',
      }),
      base('REASONING_MESSAGE_END', { messageId: 'r1' }),
      textDelta('m1', 'The answer.'),
    )

    expect(message.reasoning).toBe('Thinking…')
    expect(message.content).toBe('The answer.')
  })

  // `REASONING_MESSAGE_END` closes a segment the same way `TEXT_MESSAGE_END`
  // does, so a replayed thought does not render twice either.
  it('replaces a completed thought rather than extending it', () => {
    const thought = [
      base('REASONING_MESSAGE_START', {
        messageId: 'r1',
        role: 'reasoning',
      }),
      base('REASONING_MESSAGE_CONTENT', {
        messageId: 'r1',
        delta: 'Thinking…',
      }),
      base('REASONING_MESSAGE_END', { messageId: 'r1' }),
    ]

    const { message } = accumulate(...thought, ...thought)

    expect(message.reasoning).toBe('Thinking…')
    expect(message.parts).toEqual([{ type: 'reasoning', text: 'Thinking…' }])
  })
})

describe('tool calls', () => {
  it('pairs a result with the call that announced it', () => {
    const { message } = accumulate(...toolCall('t1', 'save_note', { filename: 'a.txt' }), ...toolResult('t1', 'saved'))

    expect(message.toolCalls).toHaveLength(1)
    expect(message.toolCalls[0]).toMatchObject({
      name: 'save_note',
      result: 'saved',
      state: 'complete',
    })
  })

  it('marks a failed call as an error and keeps the reason', () => {
    const { message } = accumulate(
      ...toolCall('t1', 'save_note', {}),
      ...toolResult('t1', 'Disk full.', {
        successful: false,
        error: 'Disk full.',
      }),
    )

    expect(message.toolCalls[0].state).toBe('error')
    expect(message.toolCalls[0].error).toBe('Disk full.')
  })

  it('distinguishes a denied call from a failed one', () => {
    const { message } = accumulate(
      ...toolCall('t1', 'save_note', {}),
      ...toolResult('t1', 'The reviewer declined.', {
        successful: false,
        denied: true,
      }),
    )

    expect(message.toolCalls[0].state).toBe('denied')
  })

  /*
   * A resumed run replays results for calls made before the pause, so a
   * result can arrive for a call *this* stream never announced.
   */
  it('records a result for a call this stream never saw announced', () => {
    const { message } = accumulate(...toolResult('t-from-before-the-pause', 'saved'))

    expect(message.toolCalls).toHaveLength(1)
    expect(message.toolCalls[0].state).toBe('complete')
  })

  it('stringifies a structured result rather than dropping it', () => {
    const { message } = accumulate(...toolResult('t1', '{"package":"laravel/mcp"}'))

    expect(message.toolCalls[0].result).toContain('laravel/mcp')
  })
})

describe('approvals', () => {
  /*
   * The paused call was announced as `tool_call` first, so it is already in
   * the list and would otherwise sit on "running" forever.
   */
  it('back-fills the state of a call that is already running', () => {
    const { message } = accumulate(
      ...toolCall('t1', 'save_note', {}),
      runFinished({
        outcome: {
          type: 'interrupt',
          interrupts: [{ id: 't1', reason: 'tool_call', toolCallId: 't1' }],
        },
      }),
    )

    expect(message.status).toBe('awaiting_approval')
    expect(message.toolCalls[0].state).toBe('awaiting_approval')
  })

  it('keeps saying it is paused when the stream then ends', () => {
    const { message } = accumulate(
      ...toolCall('t1', 'save_note', {}),
      runFinished({
        outcome: {
          type: 'interrupt',
          interrupts: [{ id: 't1', reason: 'tool_call', toolCallId: 't1' }],
        },
      }),
    )

    // A pause is not a completion, and the message must not claim otherwise.
    expect(message.status).toBe('awaiting_approval')
  })
})

describe('usage', () => {
  /*
   * Mirrors `StreamEnd::combineUsage()`. A multi-step run ends each step with
   * its own `stream_end`, so overwriting would report only the last step.
   */
  it('sums usage across the steps of a multi-step run', () => {
    const { message } = accumulate(
      runFinished({
        usage: [
          { inputTokens: 10, outputTokens: 5, totalTokens: 15 },
          { inputTokens: 3, outputTokens: 2, totalTokens: 5 },
        ],
      }),
    )

    expect(message.usage).toEqual({
      inputTokens: 13,
      outputTokens: 7,
      totalTokens: 20,
    })
  })
})

describe('provider metadata', () => {
  it('records which provider and model answered', () => {
    const { message } = accumulate(
      runFinished({
        usage: [{ provider: 'anthropic', model: 'claude-x' }],
      }),
    )

    expect(message.provider).toBe('anthropic')
    expect(message.model).toBe('claude-x')
  })
})

describe('errors', () => {
  /*
   * Typed, which the native protocol could not manage: laravel/ai serialized
   * the provider's own error type (`overloaded_error`, `stream_failed`, …)
   * into `type`, so an error could only be recognized by shape. AG-UI has one
   * event for it, and the heuristic that used to live here is deleted.
   */
  it('marks the message failed and keeps the provider\u2019s wording', () => {
    const { message } = accumulate(
      textDelta('m1', 'Partial…'),
      base('RUN_ERROR', {
        message: 'The provider is overloaded.',
        code: 'overloaded_error',
      }),
    )

    expect(message.status).toBe('error')
    expect(message.error).toBe('The provider is overloaded.')
    // Whatever arrived before the failure is still worth showing.
    expect(message.content).toBe('Partial…')
  })
})

/*
 * Settling a run `cancelled` used to leave the log ending on partial frames and nothing else, so a reader
 * that came back to it could not tell a run somebody stopped from one a killed worker truncated. The
 * terminal frame is what carries that apart, and only the message status renders the difference.
 */
describe('a run that was stopped', () => {
  it('marks a partial answer cancelled rather than finishing it', () => {
    const { message } = accumulate(
      textDelta('m1', 'Half an ans'),
      base('RUN_CANCELLED', { threadId: 'conv-1', runId: 'run-1' }),
    )

    expect(message.status).toBe('cancelled')
    expect(message.content).toBe('Half an ans')
  })

  it('leaves a truncated run streaming, since nothing said it was stopped', () => {
    expect(accumulate(textDelta('m1', 'Half an ans')).message.status).toBe('streaming')
  })

  it('keeps the failure of a run that had already reported one', () => {
    const { message } = accumulate(
      base('RUN_ERROR', { message: 'The provider is overloaded.' }),
      base('RUN_CANCELLED', { threadId: 'conv-1', runId: 'run-1' }),
    )

    expect(message.status).toBe('error')
    expect(message.error).toBe('The provider is overloaded.')
  })
})

describe('citations', () => {
  it('collects citations as they arrive', () => {
    const { message } = accumulate(
      base('CUSTOM', {
        name: 'citation',
        value: {
          title: 'Laravel MCP',
          url: 'https://laravel.com/docs/mcp',
        },
      }),
    )

    expect(message.citations).toEqual([{ title: 'Laravel MCP', url: 'https://laravel.com/docs/mcp' }])
  })
})

describe('user messages', () => {
  it('is complete the moment it is created', () => {
    const message = createUserMessage('user-1', 'Hello.')

    expect(message.status).toBe('complete')
    expect(message.content).toBe('Hello.')
  })
})

/* -------------------------------------------------------------------------- */
/* The transcript fallback                                                     */
/* -------------------------------------------------------------------------- */

describe('a messages snapshot', () => {
  it('renders the stored exchange, with no segments to accumulate', () => {
    const messages = messagesFromSnapshot([
      { id: 'sys-1', role: 'system', content: 'Be terse.' },
      { id: 'user-1', role: 'user', content: 'Hello.' },
      { id: 'assistant-1', role: 'assistant', content: 'Hi there.' },
    ])

    // The system prompt is not part of the thread anyone reads.
    expect(messages.map((message) => message.id)).toEqual(['user-1', 'assistant-1'])
    expect(messages[1]).toMatchObject({
      role: 'assistant',
      content: 'Hi there.',
      status: 'complete',
      parts: [{ type: 'text', text: 'Hi there.' }],
    })
  })

  /*
   * AG-UI carries a tool result as a message of its own, which is not how this package renders one: the
   * call the result belongs to is what the reader is looking at.
   */
  it('folds a tool result onto the call that asked for it', () => {
    const messages = messagesFromSnapshot([
      {
        id: 'assistant-1',
        role: 'assistant',
        content: 'Saved.',
        toolCalls: [
          {
            id: 'call-1',
            type: 'function',
            function: {
              name: 'SaveNote',
              arguments: '{"body":"Hi"}',
            },
          },
        ],
      },
      { id: 'tool-1', role: 'tool', toolCallId: 'call-1', content: 'ok' },
    ])

    expect(messages).toHaveLength(1)
    expect(messages[0].toolCalls).toEqual([
      {
        id: 'call-1',
        name: 'SaveNote',
        arguments: { body: 'Hi' },
        result: 'ok',
        state: 'complete',
        error: null,
      },
    ])
    expect(messages[0].parts).toEqual([
      { type: 'text', text: 'Saved.' },
      {
        type: 'dynamic-tool',
        toolName: 'SaveNote',
        toolCallId: 'call-1',
        state: 'complete',
        input: { body: 'Hi' },
        output: 'ok',
        errorText: null,
      },
    ])
  })

  /*
   * A stored turn that carried files comes back as content parts rather than as a string. Read as a
   * string it renders as `[object Object]` and the files are gone, so the text is collected from the
   * parts and the files become file parts.
   */
  it('renders the files a stored turn carried', () => {
    const messages = messagesFromSnapshot([
      {
        id: 'user-1',
        role: 'user',
        content: [
          { type: 'text', text: 'What is in this?' },
          {
            type: 'image',
            source: {
              type: 'url',
              value: 'https://example.test/receipt.png',
              mimeType: 'image/png',
            },
            metadata: { filename: 'receipt.png' },
          },
          {
            type: 'document',
            source: {
              type: 'data',
              value: 'SGk=',
              mimeType: 'application/pdf',
            },
            metadata: { filename: 'notes.pdf' },
          },
        ],
      },
    ])

    expect(messages[0].content).toBe('What is in this?')
    expect(messages[0].parts).toEqual([
      {
        type: 'file',
        filename: 'receipt.png',
        mediaType: 'image/png',
        url: 'https://example.test/receipt.png',
      },
      {
        type: 'file',
        filename: 'notes.pdf',
        mediaType: 'application/pdf',
        url: 'data:application/pdf;base64,SGk=',
      },
      { type: 'text', text: 'What is in this?' },
    ])
  })

  /*
   * A turn that paused is stored paused. The interrupts arrive on the snapshot's `RUN_FINISHED`, after
   * the transcript is rendered, and they belong to the stored message that announced the call — not to
   * the empty answer the run that served the snapshot is holding.
   */
  it('puts a pending approval on the stored message that announced the call', () => {
    const messages = messagesFromSnapshot([
      { id: 'user-1', role: 'user', content: 'Delete it.' },
      {
        id: 'assistant-1',
        role: 'assistant',
        content: 'I need a decision first.',
        toolCalls: [
          {
            id: 'call-1',
            type: 'function',
            function: { name: 'DeleteNote', arguments: '{"id":7}' },
          },
        ],
      },
    ])

    const paused = applySnapshotApprovals(messages, [
      {
        id: 'call-1',
        reason: 'approval_required',
        message: 'Delete note 7?',
        toolCallId: 'call-1',
        metadata: {
          kind: 'approval',
          toolName: 'DeleteNote',
          input: { id: 7 },
        },
      },
    ])

    expect(paused[1]).toMatchObject({
      id: 'assistant-1',
      status: 'awaiting_approval',
      approvals: [
        {
          id: 'call-1',
          tool: 'DeleteNote',
          arguments: { id: 7 },
          reason: 'Delete note 7?',
        },
      ],
    })

    // The call the turn is waiting on, which reads as complete until the interrupt is applied.
    expect(paused[1].toolCalls[0].state).toBe('awaiting_approval')
    expect(paused[1].parts.find((part) => part.type === 'dynamic-tool')).toMatchObject({
      state: 'awaiting_approval',
    })

    // Nothing else in the thread is touched.
    expect(paused[0]).toBe(messages[0])
  })

  it('ignores an interrupt that names no stored call', () => {
    const messages = messagesFromSnapshot([{ ...announcing('call-1'), id: 'assistant-1' }])

    const paused = applySnapshotApprovals(messages, [
      { id: 'call-2', reason: 'approval_required', toolCallId: 'call-2' },
      { id: 'call-1', reason: 'approval_required' },
    ])

    expect(paused).toEqual(messages)
  })

  it('renders a stored tool result that failed as an error', () => {
    const [message] = messagesFromSnapshot([
      { ...announcing('call-1'), id: 'assistant-1' },
      { id: 'result-1', role: 'tool', toolCallId: 'call-1', content: 'Disk full.', error: 'Disk full.' },
    ])

    expect(message.toolCalls[0]).toMatchObject({ result: 'Disk full.', state: 'error', error: 'Disk full.' })
    expect(message.parts[0]).toMatchObject({ state: 'error', output: 'Disk full.', errorText: 'Disk full.' })
  })

  it('renders a stored tool result that was denied as denied', () => {
    const [message] = messagesFromSnapshot([
      { ...announcing('call-1'), id: 'assistant-1' },
      {
        id: 'result-1',
        role: 'tool',
        toolCallId: 'call-1',
        content: 'The tool call was denied.',
        error: 'The tool call was denied.',
        metadata: { denied: true },
      },
    ])

    expect(message.toolCalls[0]).toMatchObject({ state: 'denied', error: 'The tool call was denied.' })
  })

  it('renders a stored tool result that succeeded as complete', () => {
    const [message] = messagesFromSnapshot([
      { ...announcing('call-1'), id: 'assistant-1' },
      { id: 'result-1', role: 'tool', toolCallId: 'call-1', content: 'Saved.' },
    ])

    expect(message.toolCalls[0]).toMatchObject({ result: 'Saved.', state: 'complete', error: null })
  })

  /*
   * Laravel AI flattens a stored answer to its text and its calls, which reads as text first. Converse
   * sends the order the steps streamed in beside it, so a reload renders the call above the answer it led to.
   */
  it('renders a stored call above the text that followed it, the way it streamed', () => {
    const [message] = messagesFromSnapshot([
      {
        ...announcing('call-1'),
        id: 'assistant-1',
        content: 'Found them.',
        parts: [
          { type: 'tool', toolCallId: 'call-1' },
          { type: 'text', text: 'Found them.' },
        ],
      },
    ])

    const streamed = accumulate(
      { type: 'STEP_STARTED', stepName: '1' },
      { type: 'TOOL_CALL_START', toolCallId: 'call-1', toolCallName: 'SaveNote' },
      { type: 'TOOL_CALL_END', toolCallId: 'call-1' },
      { type: 'TOOL_CALL_RESULT', messageId: 'result-1', toolCallId: 'call-1', content: 'ok' },
      { type: 'STEP_STARTED', stepName: '2' },
      { type: 'TEXT_MESSAGE_CONTENT', messageId: 'text-1', delta: 'Found them.' },
    ).message

    expect(message.parts.map((part) => part.type)).toEqual(['dynamic-tool', 'text'])
    expect(message.parts.map((part) => part.type)).toEqual(streamed.parts.map((part) => part.type))
    expect(message.content).toBe('Found them.')
  })

  it('renders stored reasoning, a call and the answer in the order they streamed', () => {
    const [message] = messagesFromSnapshot([
      {
        ...announcing('call-1'),
        id: 'assistant-1',
        content: 'Saved.',
        parts: [
          { type: 'reasoning', text: 'A note to save.' },
          { type: 'tool', toolCallId: 'call-1' },
          { type: 'reasoning', text: 'It worked.' },
          { type: 'text', text: 'Saved.' },
        ],
      },
    ])

    expect(message.parts.map((part) => part.type)).toEqual(['reasoning', 'dynamic-tool', 'reasoning', 'text'])
    expect(message.reasoning).toBe('A note to save.\n\nIt worked.')
  })

  it('settles an ordered stored call with the result that follows it', () => {
    const [message] = messagesFromSnapshot([
      {
        ...announcing('call-1'),
        id: 'assistant-1',
        content: 'Saved.',
        parts: [
          { type: 'tool', toolCallId: 'call-1' },
          { type: 'text', text: 'Saved.' },
        ],
      },
      { id: 'result-1', role: 'tool', toolCallId: 'call-1', content: 'ok' },
    ])

    expect(message.toolCalls[0]).toMatchObject({ result: 'ok', state: 'complete' })
    expect(message.parts[0]).toMatchObject({ type: 'dynamic-tool', state: 'complete', output: 'ok' })
  })

  it('keeps a stored call its ordered parts never named, and drops a part naming no call', () => {
    const [message] = messagesFromSnapshot([
      {
        ...announcing('call-1'),
        id: 'assistant-1',
        content: 'Saved.',
        parts: [
          { type: 'tool', toolCallId: 'call-9' },
          { type: 'text', text: 'Saved.' },
        ],
      },
    ])

    expect(message.parts.map((part) => part.type)).toEqual(['text', 'dynamic-tool'])
  })
})

describe('transcriptFrom', () => {
  it('reverses a paged transcript into reading order, without touching the page', () => {
    const page = {
      data: [
        { id: 'newest', role: 'assistant' as const, content: 'Second.' },
        { id: 'oldest', role: 'user' as const, content: 'First.' },
      ],
    }

    const messages = transcriptFrom(page, undefined)

    expect(messages.map((message) => message.id)).toEqual(['oldest', 'newest'])
    expect(page.data[0].id).toBe('newest')
  })

  it('applies the interrupts the last run paused on', () => {
    const messages = transcriptFrom(
      [{ ...announcing('call-1'), id: 'assistant-1' }],
      [{ id: 'call-1', reason: 'approval_required', toolCallId: 'call-1', message: 'Save?' }],
    )

    expect(messages[0].status).toBe('awaiting_approval')
    expect(messages[0].approvals).toEqual([{ id: 'call-1', tool: 'SaveNote', arguments: {}, reason: 'Save?' }])
  })
})

describe('parts', () => {
  /*
   * The reason parts exist. A run that thinks, calls a tool, answers, thinks
   * again and answers again is five things in an order — and a flat shape can
   * only say "all the thinking, all the tools, all the text".
   */
  it('records what arrived in the order it arrived', () => {
    const { message } = accumulate(
      base('REASONING_MESSAGE_CONTENT', {
        messageId: 'r1',
        delta: 'Let me look.',
      }),
      ...toolCall('t1', 'search', { q: 'mcp' }),
      ...toolResult('t1', 'laravel/mcp'),
      textDelta('m1', 'It is laravel/mcp.'),
    )

    expect(message.parts.map((part) => part.type)).toEqual(['reasoning', 'dynamic-tool', 'text'])
  })

  it('carries the tool call itself, not a pointer to it', () => {
    const { message } = accumulate(...toolCall('t1', 'search', { q: 'mcp' }))

    expect(message.parts[0]).toEqual({
      type: 'dynamic-tool',
      toolName: 'search',
      toolCallId: 't1',
      state: 'running',
      input: { q: 'mcp' },
      output: null,
      errorText: null,
    })
  })

  it('keeps the derived toolCalls in step with the part that holds them', () => {
    const { message } = accumulate(...toolCall('t1', 'search', {}), ...toolResult('t1', 'done'))

    const part = message.parts.find((p) => p.type === 'dynamic-tool')

    expect(part).toMatchObject({ state: 'complete', output: 'done' })
    expect(message.toolCalls[0]).toMatchObject({
      state: 'complete',
      result: 'done',
    })
  })

  it('places a citation where it was produced', () => {
    const { message } = accumulate(
      textDelta('m1', 'According to the docs,'),
      base('CUSTOM', {
        name: 'citation',
        value: {
          title: 'Laravel MCP',
          url: 'https://laravel.com/docs/mcp',
        },
      }),
    )

    expect(message.parts.map((part) => part.type)).toEqual(['text', 'source-url'])
    expect(message.citations).toHaveLength(1)
  })
})

describe('data frames', () => {
  it('appends an application frame as a namespaced part', () => {
    const { message } = accumulate(base('CUSTOM', { name: 'weather', value: { city: 'Rotterdam' } }))

    expect(message.parts[0]).toEqual({
      type: 'data-weather',
      id: null,
      data: { city: 'Rotterdam' },
    })
  })

  /*
   * The behavior that makes data frames worth having: a placeholder becoming
   * a result, without the server having to say which part to replace.
   */
  it('replaces a part when the same id is written again', () => {
    const { message } = accumulate(
      base('CUSTOM', {
        name: 'weather',
        value: { id: 'w1', status: 'loading' },
      }),
      base('CUSTOM', {
        name: 'weather',
        value: { id: 'w1', status: 'done', temp: 12 },
      }),
    )

    expect(message.parts).toHaveLength(1)
    expect(message.parts[0]).toMatchObject({
      data: { status: 'done', temp: 12 },
    })
  })

  it('appends rather than replaces when frames carry no id', () => {
    const { message } = accumulate(
      base('CUSTOM', { name: 'note', value: { text: 'one' } }),
      base('CUSTOM', { name: 'note', value: { text: 'two' } }),
    )

    expect(message.parts).toHaveLength(2)
  })

  it('does not mistake one frame name for another', () => {
    const { message } = accumulate(
      base('CUSTOM', { name: 'weather', value: { id: 'x', a: 1 } }),
      base('CUSTOM', { name: 'progress', value: { id: 'x', b: 2 } }),
    )

    expect(message.parts).toHaveLength(2)
  })
})

describe('segments that never produce content', () => {
  /*
   * Anthropic signals thinking and often never exposes its text — the thought
   * signature is encrypted. `reasoning_start` → `reasoning_end` with nothing
   * between is therefore ordinary, and it used to render an empty "Thinking"
   * box for a model that thought in private.
   */
  it('creates no reasoning part when the provider emits no deltas', () => {
    const { message } = accumulate(
      base('REASONING_MESSAGE_START', {
        messageId: 'r1',
        role: 'reasoning',
      }),
      base('REASONING_MESSAGE_END', { messageId: 'r1' }),
      textDelta('m1', 'The answer.'),
    )

    expect(message.parts.map((part) => part.type)).toEqual(['text'])
    expect(message.reasoning).toBe('')
  })

  it('creates no text part for a step that only made a tool call', () => {
    const { message } = accumulate(
      base('TEXT_MESSAGE_START', { messageId: 'm1', role: 'assistant' }),
      base('TEXT_MESSAGE_END', { messageId: 'm1' }),
      ...toolCall('t1', 'search', {}),
    )

    expect(message.parts.map((part) => part.type)).toEqual(['dynamic-tool'])
  })

  /*
   * The part appears where the content arrived, not where it was announced —
   * so a step that opens, calls a tool, then speaks renders in that order.
   */
  it('places a part at its first content, not at its start event', () => {
    const { message } = accumulate(
      base('TEXT_MESSAGE_START', { messageId: 'm1', role: 'assistant' }),
      ...toolCall('t1', 'search', {}),
      textDelta('m1', 'Found it.'),
    )

    expect(message.parts.map((part) => part.type)).toEqual(['dynamic-tool', 'text'])
  })
})

describe('a turn with nothing on screen yet', () => {
  it('is awaiting content until the first part arrives', () => {
    const started = accumulate(base('RUN_STARTED', { threadId: 't1', runId: 'r1' }))

    expect(isAwaitingContent(started.message)).toBe(true)

    // A start event alone still adds no part, so the wait covers it.
    expect(
      isAwaitingContent(
        accumulate(
          base('TEXT_MESSAGE_START', {
            messageId: 'm1',
            role: 'assistant',
          }),
        ).message,
      ),
    ).toBe(true)

    expect(isAwaitingContent(accumulate(textDelta('m1', 'Here.')).message)).toBe(false)
  })

  it('stops waiting on a turn that failed or paused before saying anything', () => {
    const failed = accumulate(base('RUN_ERROR', { message: 'Upstream is down.' }))

    expect(failed.message.parts).toEqual([])
    expect(isAwaitingContent(failed.message)).toBe(false)
  })

  it('never waits on a stored message', () => {
    expect(isAwaitingContent(createUserMessage('u1', 'Hello.'))).toBe(false)
  })
})

describe('a whole turn, as the server actually sends it', () => {
  /*
   * The frames laravel/ai emits for one answer with a tool call, in order,
   * including the three `CUSTOM` frames AG-UI has no field for. A browser test
   * cannot cover this — it drives the app in another process, where the agent
   * fake does not reach — so this is the integration guard: if the wire format
   * and this client ever disagree, it fails here.
   */
  it('folds the real frame sequence into a renderable message', () => {
    const { message } = accumulate(
      base('RUN_STARTED', { threadId: 'conv-1', runId: 'run-1' }),
      base('STEP_STARTED', { stepName: '1' }),
      base('TOOL_CALL_START', {
        toolCallId: 'call-1',
        toolCallName: 'LaravelPackageLookup',
      }),
      base('TOOL_CALL_ARGS', {
        toolCallId: 'call-1',
        delta: '{"query":"mcp"}',
      }),
      base('TOOL_CALL_END', { toolCallId: 'call-1' }),
      base('TOOL_CALL_RESULT', {
        messageId: 'result-1',
        toolCallId: 'call-1',
        content: 'laravel/mcp',
        role: 'tool',
      }),
      base('STEP_FINISHED', { stepName: '1' }),
      base('STEP_STARTED', { stepName: '2' }),
      base('TEXT_MESSAGE_START', {
        messageId: 'msg-2',
        role: 'assistant',
      }),
      base('TEXT_MESSAGE_CONTENT', {
        messageId: 'msg-2',
        delta: 'It is laravel/mcp.',
      }),
      base('TEXT_MESSAGE_END', { messageId: 'msg-2' }),
      base('STEP_FINISHED', { stepName: '2' }),
      base('RUN_FINISHED', {
        threadId: 'conv-1',
        runId: 'run-1',
        usage: [
          {
            provider: 'openai',
            model: 'gpt-test-model',
            inputTokens: 12,
            outputTokens: 8,
            totalTokens: 20,
          },
        ],
      }),
    )

    expect(message.status).toBe('complete')
    expect(message.content).toBe('It is laravel/mcp.')
    expect(message.provider).toBe('openai')
    expect(message.model).toBe('gpt-test-model')
    expect(message.usage).toMatchObject({ totalTokens: 20 })

    // The tool ran before the answer, and the transcript can say so.
    expect(message.parts.map((part) => part.type)).toEqual(['dynamic-tool', 'text'])

    expect(message.toolCalls[0]).toMatchObject({
      name: 'LaravelPackageLookup',
      arguments: { query: 'mcp' },
      result: 'laravel/mcp',
      state: 'complete',
    })
  })

  it('renders a refused tool as refused, not as answered', () => {
    const { message } = accumulate(
      ...toolCall('call-1', 'SaveDemoNote', { filename: 'a.txt' }),
      ...toolResult('call-1', 'The reviewer declined this write.', {
        successful: false,
        denied: true,
      }),
      runFinished(),
    )

    // Without `denied` on the frame a refusal is indistinguishable from an
    // answer, and the approval flow's only visible outcome would be wrong.
    expect(message.toolCalls[0].state).toBe('denied')
  })

  /*
   * `parts` is what a transcript renders, so a patch that reached `toolCalls`
   * alone left an approved call reading "awaiting approval" forever.
   */
  it('patches a tool call in the parts as well as the derived list', () => {
    const { message } = accumulate(...toolCall('call-1', 'SaveDemoNote', { filename: 'a.txt' }))

    const patched = patchToolCalls(message, (call) => ({
      ...call,
      state: 'approval_responded',
    }))

    expect(patched.toolCalls[0].state).toBe('approval_responded')
    expect(patched.parts.filter((part) => part.type === 'dynamic-tool')).toEqual([
      expect.objectContaining({
        toolCallId: 'call-1',
        toolName: 'SaveDemoNote',
        state: 'approval_responded',
      }),
    ])
  })

  it('leaves the message alone when it holds no tool calls', () => {
    const { message } = accumulate({
      type: 'TEXT_MESSAGE_START',
      messageId: 'msg-1',
      role: 'assistant',
    })

    expect(patchToolCalls(message, (call) => ({ ...call, state: 'denied' })).parts).toEqual(message.parts)
  })

  it('pauses on an interrupt outcome rather than completing', () => {
    const { message } = accumulate(
      ...toolCall('call-1', 'SaveDemoNote', { filename: 'a.txt' }),
      runFinished({
        outcome: {
          type: 'interrupt',
          interrupts: [
            {
              id: 'call-1',
              reason: 'tool_call',
              message: 'Writing a file needs a human.',
              toolCallId: 'call-1',
            },
          ],
        },
      }),
    )

    expect(message.status).toBe('awaiting_approval')
    // The arguments come off the announced call: an approval prompt that cannot
    // say what the tool was asked to do is not worth showing.
    expect(message.approvals).toEqual([
      {
        id: 'call-1',
        tool: 'SaveDemoNote',
        arguments: { filename: 'a.txt' },
        reason: 'Writing a file needs a human.',
      },
    ])
    expect(message.toolCalls[0].state).toBe('awaiting_approval')
  })

  it('ignores an interrupt that names no tool call', () => {
    const { message } = accumulate(
      ...toolCall('call-1', 'SaveDemoNote', { filename: 'a.txt' }),
      runFinished({
        outcome: { type: 'interrupt', interrupts: [{ id: 'call-1', reason: 'tool_call' }] },
      }),
    )

    expect(message.status).toBe('complete')
    expect(message.approvals).toEqual([])
  })
})

describe('a tool call its run never answered', () => {
  it('fails the calls still running when the run reports an error', () => {
    const message = accumulate(
      base('TOOL_CALL_START', { toolCallId: 'call-1', toolCallName: 'Search' }),
      base('TOOL_CALL_START', { toolCallId: 'call-2', toolCallName: 'Save' }),
      base('TOOL_CALL_RESULT', { messageId: 'r-1', toolCallId: 'call-2', content: 'Saved.' }),
      base('RUN_ERROR', { message: 'The provider fell over.' }),
    ).message

    expect(message.toolCalls.map((call) => call.state)).toEqual(['error', 'complete'])
    expect(message.parts.map((part) => (part.type === 'dynamic-tool' ? part.state : null))).toEqual([
      'error',
      'complete',
    ])
  })

  it('fails the calls still running when the run is stopped', () => {
    const message = accumulate(
      base('TOOL_CALL_START', { toolCallId: 'call-1', toolCallName: 'Search' }),
      base('RUN_CANCELLED', { threadId: 'conv-1', runId: 'run-1' }),
    ).message

    expect(message.status).toBe('cancelled')
    expect(message.toolCalls[0].state).toBe('error')
  })

  it('reads a stored call without a result as failed rather than complete', () => {
    const [message] = messagesFromSnapshot([{ id: 'a-1', ...announcing('call-1') }])

    expect(message.toolCalls[0].state).toBe('error')
  })
})

describe('timestamps', () => {
  it('carries the time the server stored each message', () => {
    const messages = messagesFromSnapshot([
      { id: 'u-1', role: 'user', content: 'Hi', createdAt: '2026-09-25T10:00:00+00:00' },
      { id: 'a-1', role: 'assistant', content: 'Hello', createdAt: '2026-09-25T10:00:02+00:00' },
      { id: 'a-2', role: 'assistant', content: 'Again', createdAt: null },
      { id: 'a-3', role: 'assistant', content: 'Unstamped' },
    ])

    expect(messages.map((message) => message.createdAt)).toEqual([
      '2026-09-25T10:00:00+00:00',
      '2026-09-25T10:00:02+00:00',
      null,
      null,
    ])
  })
})
