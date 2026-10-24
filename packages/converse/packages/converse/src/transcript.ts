import {
  applySnapshotApprovals,
  messagesFromSnapshot,
  patchToolCalls,
  toolCallStateFrom,
  transcriptFrom,
} from './message'
import type {
  AgUiInterrupt,
  AgUiMessage,
  ChatMessage,
  ChatSource,
  ChatToolCall,
  PendingApproval,
  ToolCallResultEvent,
} from './types'

/**
 * The messages of a turn this browser is rendering itself.
 */
export type LiveTurn = { messages: ChatMessage[] }

/**
 * Where the transcript reads the server's own account of the thread from.
 */
export type TranscriptConfig = {
  source: () => ChatSource
  history?: () => ChatMessage[] | undefined
}

/**
 * What this browser knows about a call the server has already answered.
 */
type ResolvedCall = Pick<ChatToolCall, 'result' | 'state' | 'error'>

/**
 * The payload's transcript as last rendered, kept so its messages hold their identity between reads.
 */
type RenderedSource = {
  messages: ChatSource['messages']
  interrupts: ChatSource['interrupts']
  rendered: ChatMessage[]
}

/**
 * The transcript a chat renders, being the server's own with this browser's turns folded in.
 */
export class Transcript {
  protected turns: LiveTurn[] = []

  protected snapshot: ChatMessage[] | null = null

  protected answered = new Set<string>()

  protected overrides = new Map<string, PendingApproval[]>()

  protected resolved = new Map<string, ResolvedCall>()

  // Keyed by the stored message itself, so a payload that carries its updated copy ends the continuation.
  protected continuations = new WeakMap<ChatMessage, ChatMessage>()

  protected renderedSource: RenderedSource | null = null

  constructor(protected config: TranscriptConfig) {}

  /**
   * Open a turn for this browser to render.
   */
  public openTurn(): LiveTurn {
    const turn: LiveTurn = { messages: [] }

    this.turns = [...this.turns, turn]

    return turn
  }

  /**
   * Drop a turn, whatever it rendered.
   */
  public discardTurn(turn: LiveTurn): void {
    this.turns = this.turns.filter((candidate) => candidate !== turn)
  }

  /**
   * Drop a turn that ended with nothing on screen.
   */
  public discardIfEmpty(turn: LiveTurn): void {
    if (turn.messages.length === 0) {
      this.discardTurn(turn)
    }
  }

  /**
   * Move a turn behind every other, as a turn waiting on the one after it is.
   */
  public moveToEnd(turn: LiveTurn): void {
    this.turns = [...this.turns.filter((candidate) => candidate !== turn), turn]
  }

  /**
   * Drop the answers a stopped or failed run left on screen, which a retry replaces rather than repeats.
   */
  public discardStoppedAnswers(): void {
    for (const turn of this.turns) {
      turn.messages = turn.messages.filter(
        (message) => message.role !== 'assistant' || (message.status !== 'cancelled' && message.status !== 'error'),
      )
    }
  }

  /**
   * Get the transcript, as the server last described it.
   */
  public stored(history: ChatMessage[] | undefined = this.config.history?.()): ChatMessage[] {
    // A history getter that answers nothing leaves the payload's transcript in charge.
    const source = history ?? this.renderSource()

    // A snapshot is newer than the transcript the page was rendered with, which only adds the pages before it.
    return this.snapshot === null ? source : [...olderThan(source, this.snapshot), ...this.snapshot]
  }

  /**
   * Render the payload's transcript, reusing the last rendering while the payload has not moved.
   */
  protected renderSource(): ChatMessage[] {
    const { messages, interrupts } = this.config.source()
    const previous = this.renderedSource

    if (previous !== null && previous.messages === messages && previous.interrupts === interrupts) {
      return previous.rendered
    }

    this.renderedSource = { messages, interrupts, rendered: transcriptFrom(messages, interrupts) }

    if (previous !== null) {
      this.carryContinuations(previous.rendered, this.renderedSource.rendered)
    }

    return this.renderedSource.rendered
  }

  /**
   * Keep each answer on the rendering of its paused message that a re-rendered payload still carries paused, as one an
   * older page merged into does.
   */
  protected carryContinuations(previous: ChatMessage[], rendered: ChatMessage[]): void {
    const answers = new Map<string, ChatMessage>()

    for (const message of previous) {
      const answer = this.continuations.get(message)

      if (answer !== undefined) {
        answers.set(message.id, answer)
      }
    }

    for (const message of rendered) {
      const answer = answers.get(message.id)

      if (answer !== undefined && message.status === 'awaiting_approval') {
        this.continuations.set(message, answer)
      }
    }
  }

  /**
   * Get the transcript, with the turns this browser streamed folded in.
   */
  public messages(history?: ChatMessage[], streaming: ChatMessage[] = []): ChatMessage[] {
    const stored = this.stored(history)
    const storedIds = new Set(stored.map((message) => message.id))

    // Per turn rather than per message, since the prompt keeps its local id until the run stores it.
    const live = this.turns
      .filter((turn) => !turn.messages.some((message) => storedIds.has(message.id)))
      .flatMap((turn) => turn.messages)

    const continued = new Set([...stored, ...live].map((message) => this.continuations.get(message)))

    // The answers on the wire are mutated in place, so they are copied into every snapshot.
    return [...stored, ...live.filter((message) => !continued.has(message))].map((message) =>
      this.renderContinued(message, streaming.includes(message) ? { ...message } : message),
    )
  }

  /**
   * Render a paused message the server updated in place with the answer that resumed it, until the transcript carries
   * the updated copy.
   */
  public continueStored(answer: ChatMessage): void {
    let paused = this.everyMessage().find(
      (message) => message !== answer && message.id === answer.id && message.status === 'awaiting_approval',
    )

    // A resumed run that paused again on the same row is continued in turn.
    for (
      let next = paused && this.continuations.get(paused);
      next !== undefined && next !== answer;
      next = this.continuations.get(next)
    ) {
      paused = next
    }

    if (paused !== undefined) {
      this.continuations.set(paused, answer)
      this.answered.delete(answer.id)
    }
  }

  /**
   * Determine if the transcript or a turn this browser is rendering carries a message under one of the ids.
   */
  public carries(ids: string[]): boolean {
    return this.everyMessage().some((message) => ids.includes(message.id))
  }

  /**
   * Determine if a message outside the given turn carries the tool call, as a paused one being resumed does.
   */
  public carriesToolCall(toolCallId: string, except: LiveTurn): boolean {
    return this.everyMessage(except).some((message) => message.toolCalls.some((call) => call.id === toolCallId))
  }

  /**
   * Get the transcript and the messages of every turn this browser is rendering, but the one left out.
   */
  protected everyMessage(except?: LiveTurn): ChatMessage[] {
    const turns = this.turns.filter((turn) => turn !== except)

    return [...this.stored(), ...turns.flatMap((turn) => turn.messages)]
  }

  /**
   * Replace the transcript with the server's own, keeping only the turns still in flight.
   */
  public adoptSnapshot(messages: AgUiMessage[] | undefined, keep: LiveTurn[]): void {
    this.snapshot = messagesFromSnapshot(messages)
    this.answered.clear()
    this.overrides.clear()
    this.resolved.clear()
    this.continuations = new WeakMap()

    const kept = new Set(keep)

    this.turns = this.turns.filter((turn) => kept.has(turn))
  }

  /**
   * Determine if a snapshot has replaced the transcript the page was rendered with.
   */
  public hasSnapshot(): boolean {
    return this.snapshot !== null
  }

  /**
   * Fold the interrupts that close a snapshot into it.
   */
  public closeSnapshot(interrupts: AgUiInterrupt[]): void {
    if (this.snapshot !== null) {
      this.snapshot = applySnapshotApprovals(this.snapshot, interrupts)
    }
  }

  /**
   * Record that the approvals a message was waiting on have been answered.
   */
  public markAnswered(messageId: string): void {
    this.answered.add(messageId)
    this.overrides.delete(messageId)
  }

  /**
   * Forget that the approvals a message was waiting on have been answered, leaving a result the stream reports later
   * free to answer them still.
   */
  public unmarkAnswered(messageId: string): void {
    this.answered.delete(messageId)
  }

  /**
   * Put the approvals the server still has pending back on the message.
   */
  public restoreApprovals(messageId: string, approvals: PendingApproval[]): void {
    this.answered.delete(messageId)
    this.overrides.set(messageId, approvals)
  }

  /**
   * Record how a replayed call turned out, which answers the approval the message carrying it was waiting on.
   */
  public recordResolvedCall(event: ToolCallResultEvent): void {
    this.resolved.set(event.toolCallId, {
      result: event.content,
      error: event.metadata?.error ?? null,
      state: toolCallStateFrom(event),
    })

    const paused = this.everyMessage().find((message) => message.toolCalls.some((call) => call.id === event.toolCallId))

    if (paused !== undefined && !this.overrides.has(paused.id)) {
      this.answered.add(paused.id)
    }
  }

  /**
   * Forget everything this browser was holding for the thread.
   */
  public forget(): void {
    this.turns = []
    this.snapshot = null
    this.answered.clear()
    this.overrides.clear()
    this.resolved.clear()
    this.continuations = new WeakMap()
  }

  /**
   * Apply what this browser knows to a message, followed by the answer that resumed it where one did.
   */
  protected renderContinued(message: ChatMessage, copy: ChatMessage): ChatMessage {
    const answer = this.continuations.get(message)
    const rendered = this.render(copy)

    return answer === undefined ? rendered : continuedBy(rendered, this.continued(answer))
  }

  /**
   * Get an answer joined with the answers that resumed it in turn.
   */
  protected continued(answer: ChatMessage): ChatMessage {
    const next = this.continuations.get(answer)

    return next === undefined ? this.render(answer) : continuedBy(this.render(answer), this.continued(next))
  }

  /**
   * Apply what this browser knows to a message the transcript carries.
   */
  protected render(message: ChatMessage): ChatMessage {
    return this.withResolvedCalls(this.withApprovals(message))
  }

  /**
   * Apply what this browser knows about a message's approvals.
   */
  protected withApprovals(message: ChatMessage): ChatMessage {
    const override = this.overrides.get(message.id)

    if (override !== undefined) {
      return {
        ...message,
        approvals: override,
        status: override.length === 0 ? 'complete' : 'awaiting_approval',
      }
    }

    if (message.approvals.length === 0 || !this.answered.has(message.id)) {
      return message
    }

    // Answered, not finished: a slow or rejected resume must not look complete.
    return patchToolCalls({ ...message, approvals: [], status: 'approval_responded' }, (call) =>
      call.state === 'awaiting_approval' ? { ...call, state: 'approval_responded' as const } : call,
    )
  }

  /**
   * Fold the results of replayed calls into the message that announced them.
   */
  protected withResolvedCalls(message: ChatMessage): ChatMessage {
    if (this.resolved.size === 0 || !message.toolCalls.some((call) => this.resolved.has(call.id))) {
      return message
    }

    return patchToolCalls(message, (call) => {
      const resolved = this.resolved.get(call.id)

      return resolved === undefined ? call : { ...call, ...resolved }
    })
  }
}

/**
 * Join a paused message and the answer that resumed it into the one message the server stored them as.
 */
function continuedBy(paused: ChatMessage, answer: ChatMessage): ChatMessage {
  return {
    ...answer,
    content: joinText(paused.content, answer.content),
    reasoning: joinText(paused.reasoning, answer.reasoning),
    toolCalls: [...paused.toolCalls, ...answer.toolCalls],
    citations: [...paused.citations, ...answer.citations],
    createdAt: paused.createdAt ?? answer.createdAt,
    parts: [...paused.parts, ...answer.parts],
  }
}

/**
 * Join the text of two steps the way `combineSegments()` does.
 */
function joinText(first: string, second: string): string {
  return [first, second].filter((text) => text.trim() !== '').join('\n\n')
}

/**
 * Get the messages of a transcript that come before the first one the newer transcript also carries, being none where
 * the two never meet.
 */
function olderThan(transcript: ChatMessage[], newer: ChatMessage[]): ChatMessage[] {
  const ids = new Set(newer.map((message) => message.id))
  const overlap = transcript.findIndex((message) => ids.has(message.id))

  return overlap === -1 ? [] : transcript.slice(0, overlap)
}

/**
 * Get the newest message that is waiting on an approval.
 */
export function pausedMessage(messages: ChatMessage[]): ChatMessage | null {
  for (let index = messages.length - 1; index >= 0; index--) {
    if (messages[index].status === 'awaiting_approval') {
      return messages[index]
    }
  }

  return null
}
