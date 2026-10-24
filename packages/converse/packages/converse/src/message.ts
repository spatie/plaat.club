import type {
  AgUiContentPart,
  AgUiEvent,
  AgUiInterrupt,
  AgUiMessage,
  AgUiStoredPart,
  AgUiUsage,
  ChatFilePart,
  ChatMessage,
  ChatMessagePart,
  ChatToolCall,
  ChatTranscript,
  ChatToolCallState,
  ChatDataPart,
  ChatSourcePart,
  ChatToolPart,
  CustomEvent,
  PendingApproval,
  RunFinishedEvent,
  ToolCallResultEvent,
} from './types'

/**
 * One run of text or reasoning, and the step of the run it belongs to.
 */
type Segment = { step: number; text: string; closed: boolean }

/**
 * The two kinds of text a run streams.
 */
type SegmentKind = 'text' | 'reasoning'

/**
 * Get the state a tool call's result leaves it in.
 */
export function toolCallStateFrom(event: ToolCallResultEvent): ChatToolCallState {
  if (event.metadata === undefined) {
    return 'complete'
  }

  return event.metadata.denied === true ? 'denied' : 'error'
}

/**
 * Get the state a stored tool result leaves its call in.
 */
function storedToolCallState(message: AgUiMessage): ChatToolCallState {
  if (message.metadata?.denied === true) {
    return 'denied'
  }

  return message.error === undefined ? 'complete' : 'error'
}

/**
 * Render the server's transcript, with the approvals its last run paused on.
 */
export function transcriptFrom(
  transcript: ChatTranscript | undefined,
  interrupts: AgUiInterrupt[] | undefined,
): ChatMessage[] {
  return applySnapshotApprovals(messagesFromSnapshot(oldestFirst(transcript)), interrupts ?? [])
}

/**
 * Flatten either transcript shape into one array of messages, oldest first.
 */
function oldestFirst(transcript: ChatTranscript | undefined): AgUiMessage[] {
  if (transcript === undefined) {
    return []
  }

  if (Array.isArray(transcript)) {
    return transcript
  }

  // Reversed as a copy, since the array belongs to whoever owns the transcript.
  return withoutRepeats(transcript.data ?? []).reverse()
}

/**
 * Drop the messages a page merged in twice, keeping each where it first appeared.
 */
function withoutRepeats(newestFirst: AgUiMessage[]): AgUiMessage[] {
  const seen = new Set<string>()

  return newestFirst.filter((message) => {
    if (seen.has(message.id)) {
      return false
    }

    seen.add(message.id)

    return true
  })
}

/**
 * A turn that has started but has nothing to show yet.
 */
export function isAwaitingContent(message: ChatMessage): boolean {
  return message.status === 'streaming' && message.parts.length === 0
}

/**
 * Create the message for a turn this browser is sending.
 */
export function createUserMessage(id: string, content: string, files: ChatFilePart[] = []): ChatMessage {
  return {
    ...emptyMessage(id, 'user'),
    content,
    status: 'complete',
    parts: [...files, ...(content === '' ? [] : [{ type: 'text' as const, text: content }])],
  }
}

/**
 * Render `MESSAGES_SNAPSHOT` as a transcript.
 */
export function messagesFromSnapshot(messages: AgUiMessage[] | undefined): ChatMessage[] {
  const rendered: ChatMessage[] = []

  for (const message of messages ?? []) {
    if (message.role === 'user') {
      rendered.push({
        ...createUserMessage(message.id, textOf(message.content), filesOf(message)),
        createdAt: message.createdAt ?? null,
      })

      continue
    }

    if (message.role === 'tool') {
      applyStoredToolResult(rendered, message)

      continue
    }

    if (message.role === 'assistant') {
      rendered.push(assistantFromSnapshot(message))
    }
  }

  return rendered
}

/**
 * Fold a settled run's open approvals into the transcript it was served with.
 */
export function applySnapshotApprovals(messages: ChatMessage[], interrupts: AgUiInterrupt[]): ChatMessage[] {
  if (interrupts.length === 0) {
    return messages
  }

  const rendered = [...messages]

  for (const interrupt of withToolCall(interrupts)) {
    const id = interrupt.toolCallId
    const index = indexOfCall(rendered, id)

    if (index === -1) {
      continue
    }

    const message = rendered[index]
    const call = message.toolCalls.find((candidate) => candidate.id === id)

    rendered[index] = patchToolCalls(
      {
        ...message,
        approvals: [...message.approvals, approvalFrom(interrupt, call)],
        status: 'awaiting_approval',
      },
      (candidate) => (candidate.id === id ? { ...candidate, state: 'awaiting_approval' } : candidate),
    )
  }

  return rendered
}

/**
 * An interrupt that names the tool call it paused on.
 */
type ToolCallInterrupt = AgUiInterrupt & { toolCallId: string }

/**
 * Keep the interrupts that name the tool call they paused on, which every approval the server sends does.
 */
function withToolCall(interrupts: AgUiInterrupt[]): ToolCallInterrupt[] {
  return interrupts.filter((interrupt): interrupt is ToolCallInterrupt => typeof interrupt.toolCallId === 'string')
}

/**
 * Build the approval an interrupt asks for, preferring what the call itself announced.
 */
function approvalFrom(interrupt: ToolCallInterrupt, call: ChatToolCall | undefined): PendingApproval {
  return {
    id: interrupt.toolCallId,
    tool: call?.name || (interrupt.metadata?.toolName ?? ''),
    arguments: call?.arguments ?? interrupt.metadata?.input ?? {},
    reason: interrupt.message ?? null,
  }
}

/**
 * Get the message that announced a call.
 */
function indexOfCall(messages: ChatMessage[], toolCallId: string): number {
  for (let index = messages.length - 1; index >= 0; index--) {
    if (messages[index].toolCalls.some((call) => call.id === toolCallId)) {
      return index
    }
  }

  return -1
}

/**
 * Render a stored assistant message.
 */
function assistantFromSnapshot(message: AgUiMessage): ChatMessage {
  const content = textOf(message.content)

  const calls: ChatToolCall[] = (message.toolCalls ?? []).map((call) => ({
    id: call.id,
    name: call.function.name,
    arguments: parseArguments(call.function.arguments),
    result: null,
    // A stored call only lacks its result when the run stopped short of it, unless `applySnapshotApprovals` flips it.
    state: 'error',
    error: null,
  }))

  const stored = message.parts ?? []

  const settled: ChatMessage = {
    ...emptyMessage(message.id, 'assistant'),
    content,
    status: 'complete',
    createdAt: message.createdAt ?? null,
    toolCalls: calls,
    usage: message.usage === undefined ? null : usageFrom(message.usage),
    provider: message.usage?.provider ?? null,
    model: message.usage?.model ?? null,
  }

  if (stored.length === 0) {
    return {
      ...settled,
      parts: [...(content === '' ? [] : [{ type: 'text' as const, text: content }]), ...calls.map(toToolPart)],
    }
  }

  const reasoning = stored.flatMap((part) => (part.type === 'reasoning' ? [part.text] : []))
  const parts = orderedParts(stored, calls)

  return {
    ...settled,
    reasoning: reasoning.join('\n\n'),
    citations: citationsFrom(parts),
    parts,
  }
}

/**
 * Render a stored answer's parts in the order they streamed, keeping any call they never named at the end.
 */
function orderedParts(stored: AgUiStoredPart[], calls: ChatToolCall[]): ChatMessagePart[] {
  const named = new Set<string>()

  const parts = stored.flatMap((part): ChatMessagePart[] => {
    if (part.type === 'custom') {
      return [customPart(part)]
    }

    if (part.type !== 'tool') {
      return [{ type: part.type, text: part.text }]
    }

    const call = calls.find((candidate) => candidate.id === part.toolCallId)

    if (call === undefined) {
      return []
    }

    named.add(call.id)

    return [toToolPart(call)]
  })

  return [...parts, ...calls.filter((call) => !named.has(call.id)).map(toToolPart)]
}

/**
 * Render a stored tool result on the call that asked for it.
 */
function applyStoredToolResult(rendered: ChatMessage[], message: AgUiMessage): void {
  const toolCallId = message.toolCallId ?? ''

  for (let index = rendered.length - 1; index >= 0; index--) {
    if (!rendered[index].toolCalls.some((call) => call.id === toolCallId)) {
      continue
    }

    rendered[index] = patchToolCalls(rendered[index], (call) =>
      call.id === toolCallId
        ? {
            ...call,
            result: textOf(message.content),
            state: storedToolCallState(message),
            error: message.error ?? null,
          }
        : call,
    )

    return
  }
}

/**
 * Apply a change to a tool call in the parts and in the list derived from them.
 */
export function patchToolCalls(message: ChatMessage, patch: (call: ChatToolCall) => ChatToolCall): ChatMessage {
  return {
    ...message,
    toolCalls: message.toolCalls.map(patch),
    parts: message.parts.map((part) => (part.type === 'dynamic-tool' ? toToolPart(patch(toToolCall(part))) : part)),
  }
}

/**
 * Folds an AG-UI stream into one assistant message, mutating it in place.
 */
export class StreamedMessage {
  /**
   * The message the stream is being folded into.
   */
  public message: ChatMessage

  /**
   * The run's current step, as `STEP_STARTED` reports it.
   */
  protected step = 0

  /**
   * The text segments, by message id.
   */
  protected textSegments = new Map<string, Segment>()

  /**
   * The reasoning segments, by message id.
   */
  protected reasoningSegments = new Map<string, Segment>()

  /**
   * Where each step's part sits in `parts`, per kind.
   */
  protected partIndexes: Record<SegmentKind, Map<number, number>> = {
    text: new Map(),
    reasoning: new Map(),
  }

  /**
   * Where each tool call's part sits in `parts`.
   */
  protected toolCallIndexes = new Map<string, number>()

  /**
   * The `TOOL_CALL_ARGS` deltas, accumulated until the call closes.
   */
  protected toolCallArguments = new Map<string, string>()

  /**
   * Create a new streamed message.
   */
  constructor(id: string) {
    this.message = emptyMessage(id, 'assistant')
  }

  /**
   * Apply an event to the message.
   */
  public apply(event: AgUiEvent): this {
    switch (event.type) {
      case 'RUN_ERROR':
        this.message.status = 'error'
        this.message.error = event.message
        this.settleRunningCalls()
        break

      case 'STEP_STARTED':
        this.step += 1
        break

      case 'TEXT_MESSAGE_START':
        this.startSegment('text', event.messageId)
        break

      case 'TEXT_MESSAGE_CONTENT':
        this.appendSegment('text', event.messageId, event.delta)
        break

      case 'REASONING_MESSAGE_START':
        this.startSegment('reasoning', event.messageId)
        break

      case 'REASONING_MESSAGE_CONTENT':
        this.appendSegment('reasoning', event.messageId, event.delta)
        break

      case 'TEXT_MESSAGE_END':
        this.closeSegment('text', event.messageId)
        break

      case 'REASONING_MESSAGE_END':
        this.closeSegment('reasoning', event.messageId)
        break

      case 'TOOL_CALL_START':
        this.recordToolCall({
          id: event.toolCallId,
          name: event.toolCallName,
          arguments: {},
          result: null,
          state: 'running',
          error: null,
        })
        break

      // Accumulated rather than parsed per frame, since partial JSON does not parse.
      case 'TOOL_CALL_ARGS':
        this.toolCallArguments.set(event.toolCallId, (this.toolCallArguments.get(event.toolCallId) ?? '') + event.delta)
        break

      case 'TOOL_CALL_END':
        this.updateToolCall(event.toolCallId, (call) => {
          call.arguments = parseArguments(this.toolCallArguments.get(event.toolCallId))
        })
        break

      case 'TOOL_CALL_RESULT':
        this.applyToolCallResult(event)
        break

      case 'RUN_FINISHED':
        this.applyRunFinished(event)
        break

      // A run that already reported an error keeps it: being stopped does not change what went wrong.
      case 'RUN_CANCELLED':
        if (this.message.status === 'streaming') {
          this.message.status = 'cancelled'
        }

        this.settleRunningCalls()
        break

      case 'CUSTOM':
        this.applyCustom(event)
        break

      default:
        break
    }

    return this
  }

  /**
   * Determine if the event opens another message, which a handler run, having no steps, does each time it sends.
   */
  public startsAnotherMessage(event: AgUiEvent): boolean {
    if (event.type !== 'TEXT_MESSAGE_START' || this.step > 0 || this.textSegments.has(event.messageId)) {
      return false
    }

    const segments = [...this.textSegments.values()]

    return segments.some((segment) => segment.text !== '') && segments.every((segment) => segment.closed)
  }

  /**
   * Fail the calls a stopped run never answered, which would otherwise read as running forever.
   */
  protected settleRunningCalls(): void {
    for (const call of this.message.toolCalls) {
      if (call.state === 'running') {
        this.updateToolCall(call.id, (settled) => {
          settled.state = 'error'
        })
      }
    }
  }

  /**
   * Resolve the call a result belongs to.
   */
  protected applyToolCallResult(event: ToolCallResultEvent): void {
    this.updateToolCall(
      event.toolCallId,
      (call) => {
        call.result = event.content
        call.error = event.metadata?.error ?? null
        call.state = toolCallStateFrom(event)
      },
      () => ({
        id: event.toolCallId,
        name: '',
        arguments: {},
        result: null,
        state: 'running',
        error: null,
      }),
    )
  }

  /**
   * Apply the usage, the model and any approvals the finished run carries.
   */
  protected applyRunFinished(event: RunFinishedEvent): void {
    for (const usage of event.usage ?? []) {
      this.message.usage = mergeUsage(this.message.usage, usageFrom(usage))

      this.message.provider = usage.provider ?? this.message.provider
      this.message.model = usage.model ?? this.message.model
    }

    const interrupts = withToolCall(event.outcome?.interrupts ?? [])

    if (interrupts.length === 0) {
      // A run that paused also finishes; only one that answered is complete.
      if (this.message.status === 'streaming') {
        this.message.status = 'complete'
      }

      return
    }

    this.message.approvals = interrupts.map((interrupt) =>
      approvalFrom(interrupt, this.toolCallFor(interrupt.toolCallId)),
    )

    this.message.status = 'awaiting_approval'

    // The paused call was announced first, so it would otherwise sit on "running" forever.
    for (const approval of this.message.approvals) {
      this.updateToolCall(approval.id, (call) => {
        call.state = 'awaiting_approval'
      })
    }
  }

  /**
   * Apply an extension event, which is a citation or a data part.
   */
  protected applyCustom(event: CustomEvent): void {
    const part = customPart(event)

    if (part.type !== 'source-url') {
      this.recordDataPart(part)

      return
    }

    this.message.parts = [...this.message.parts, part]
    this.message.citations = citationsFrom(this.message.parts)
  }

  /**
   * Get the segments of a kind.
   */
  protected segmentsFor(kind: SegmentKind): Map<string, Segment> {
    return kind === 'text' ? this.textSegments : this.reasoningSegments
  }

  /**
   * Open the segment a message id names, leaving the part to wait for the first delta, since a model that
   * thinks in private sends a start and never a delta.
   */
  protected startSegment(kind: SegmentKind, id: string): Segment {
    const segments = this.segmentsFor(kind)
    const existing = segments.get(id)

    if (existing !== undefined) {
      return existing
    }

    const segment: Segment = { step: this.step, text: '', closed: false }

    segments.set(id, segment)

    return segment
  }

  /**
   * Mark a segment finished.
   */
  protected closeSegment(kind: SegmentKind, id: string): void {
    const segment = this.segmentsFor(kind).get(id)

    if (segment !== undefined) {
      segment.closed = true
    }
  }

  /**
   * Get the segment a delta belongs to, replacing one that has already closed.
   */
  protected openSegment(kind: SegmentKind, id: string): Segment {
    const segments = this.segmentsFor(kind)
    const existing = segments.get(id)

    if (existing === undefined || !existing.closed) {
      return this.startSegment(kind, id)
    }

    const segment: Segment = {
      step: existing.step,
      text: '',
      closed: false,
    }

    segments.set(id, segment)

    return segment
  }

  /**
   * Add a delta to its segment, and to the part the step renders.
   */
  protected appendSegment(kind: SegmentKind, id: string, delta: string): void {
    const segments = this.segmentsFor(kind)
    const segment = this.openSegment(kind, id)

    const isFirstContent = segment.text === '' && delta !== ''

    // Asked before the delta lands, or this segment counts as its own precedent.
    const stepAlreadyOpen = this.hasPartForStep(kind, segment.step)

    segment.text += delta

    // A part per step, not per segment: a provider that cites its sources closes a block at every citation.
    if (isFirstContent && !stepAlreadyOpen) {
      this.partIndexes[kind].set(segment.step, this.message.parts.length)
      this.message.parts = [...this.message.parts, { type: kind, text: '' }]
    }

    if (kind === 'text') {
      this.message.content = combineSegments(segments)
    } else {
      this.message.reasoning = combineSegments(segments)
    }

    this.updatePartForStep(kind, segment.step, textForStep(segments, segment.step))
  }

  /**
   * Determine if a step has opened a part of this kind.
   */
  protected hasPartForStep(kind: SegmentKind, step: number): boolean {
    return this.partIndexes[kind].has(step)
  }

  /**
   * Rewrite a step's part, addressed by step rather than by position.
   */
  protected updatePartForStep(kind: SegmentKind, step: number, text: string): void {
    const index = this.partIndexes[kind].get(step)

    if (index === undefined || this.message.parts[index]?.type !== kind) {
      return
    }

    const parts = [...this.message.parts]

    parts[index] = { type: kind, text }
    this.message.parts = parts
  }

  /**
   * Append a tool call as a part, which is the only place it is held.
   */
  protected recordToolCall(call: ChatToolCall): void {
    if (this.toolCallIndexes.has(call.id)) {
      return
    }

    this.toolCallIndexes.set(call.id, this.message.parts.length)
    this.message.parts = [...this.message.parts, toToolPart(call)]

    this.deriveToolCalls()
  }

  /**
   * Apply a change to a tool call, announcing one this stream never saw.
   */
  protected updateToolCall(
    toolCallId: string,
    update: (call: ChatToolCall) => void,
    announce?: () => ChatToolCall,
  ): void {
    if (!this.toolCallIndexes.has(toolCallId) && announce !== undefined) {
      this.recordToolCall(announce())
    }

    const index = this.toolCallIndexes.get(toolCallId)

    if (index === undefined) {
      return
    }

    const parts = [...this.message.parts]
    const part = parts[index]

    if (part?.type !== 'dynamic-tool') {
      return
    }

    const call = toToolCall(part)

    update(call)

    parts[index] = toToolPart(call)
    this.message.parts = parts

    this.deriveToolCalls()
  }

  /**
   * Get a tool call by its id.
   */
  protected toolCallFor(toolCallId: string): ChatToolCall | undefined {
    return this.message.toolCalls.find((call) => call.id === toolCallId)
  }

  /**
   * Derive the tool calls from the parts that hold them.
   */
  protected deriveToolCalls(): void {
    this.message.toolCalls = this.message.parts
      .filter((part): part is ChatToolPart => part.type === 'dynamic-tool')
      .map(toToolCall)
  }

  /**
   * Record a data part, replacing the one that shares its id.
   */
  protected recordDataPart(part: ChatDataPart): void {
    const existing =
      part.id === null
        ? -1
        : this.message.parts.findIndex((candidate) => candidate.type === part.type && candidate.id === part.id)

    if (existing === -1) {
      this.message.parts = [...this.message.parts, part]

      return
    }

    const parts = [...this.message.parts]

    parts[existing] = part
    this.message.parts = parts
  }
}

/**
 * Render an extension event as its part, which is a source for a citation and a data part otherwise.
 */
function customPart(event: Pick<CustomEvent, 'name' | 'value'>): ChatSourcePart | ChatDataPart {
  if (event.name !== 'citation') {
    return {
      type: `data-${event.name}`,
      id: typeof event.value.id === 'string' ? event.value.id : null,
      data: event.value,
    }
  }

  return {
    type: 'source-url',
    sourceId: String((event.value.url as string | null) ?? ''),
    url: String((event.value.url as string | null) ?? ''),
    title: (event.value.title as string | null) ?? null,
  }
}

/**
 * Derive the citations from the parts, one per source however often it is cited.
 */
function citationsFrom(parts: ChatMessagePart[]): ChatMessage['citations'] {
  const seen = new Set<string>()
  const citations: ChatMessage['citations'] = []

  for (const part of parts) {
    if (part.type !== 'source-url' || seen.has(part.url)) {
      continue
    }

    seen.add(part.url)
    citations.push({ title: part.title, url: part.url })
  }

  return citations
}

/**
 * Read the token counts out of the usage a run reported.
 */
function usageFrom(usage: AgUiUsage): ChatMessage['usage'] {
  return {
    inputTokens: usage.inputTokens ?? null,
    outputTokens: usage.outputTokens ?? null,
    totalTokens: usage.totalTokens ?? null,
  }
}

/**
 * Get the text of a message, whose content is a string unless its turn carried files.
 */
function textOf(content: AgUiMessage['content']): string {
  if (typeof content === 'string') {
    return content
  }

  if (!Array.isArray(content)) {
    return ''
  }

  return content
    .filter((part) => part.type === 'text')
    .map((part) => part.text ?? '')
    .join('')
}

/**
 * Get the files a message carried.
 */
function filesOf(message: AgUiMessage): ChatFilePart[] {
  if (!Array.isArray(message.content)) {
    return []
  }

  const files: ChatFilePart[] = []

  for (const part of message.content) {
    const source = part.source

    if (part.type === 'text' || source === null || source === undefined) {
      continue
    }

    files.push({
      type: 'file',
      filename: part.metadata?.filename ?? '',
      mediaType: source.mimeType ?? null,
      url: source.type === 'url' ? source.value : dataUrl(source),
    })
  }

  return files
}

/**
 * Get a data URL for bytes the message carried inline.
 */
function dataUrl(source: NonNullable<AgUiContentPart['source']>): string | null {
  return source.mimeType ? `data:${source.mimeType};base64,${source.value}` : null
}

/**
 * Parse a tool call's arguments, which arrive as a JSON string that may be truncated.
 */
function parseArguments(raw: string | undefined): Record<string, unknown> {
  if (raw === undefined || raw.trim() === '') {
    return {}
  }

  try {
    const parsed: unknown = JSON.parse(raw)

    return parsed !== null && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {}
  } catch {
    return {}
  }
}

/**
 * Create a message with nothing in it.
 */
function emptyMessage(id: string, role: 'user' | 'assistant'): ChatMessage {
  return {
    id,
    role,
    content: '',
    reasoning: '',
    status: role === 'user' ? 'complete' : 'streaming',
    toolCalls: [],
    approvals: [],
    citations: [],
    usage: null,
    error: null,
    provider: null,
    model: null,
    createdAt: null,
    parts: [],
  }
}

/**
 * Join the text a step has accumulated.
 */
function textForStep(segments: Map<string, Segment>, step: number): string {
  return [...segments.values()]
    .filter((segment) => segment.step === step)
    .map((segment) => segment.text)
    .join('')
}

/**
 * Join the segments the way `TextDelta::combine()` does.
 */
function combineSegments(segments: Map<string, Segment>): string {
  // Each step is one utterance, so steps join with a blank line and blocks within a step with nothing.
  const steps = new Map<number, string>()

  for (const segment of segments.values()) {
    steps.set(segment.step, (steps.get(segment.step) ?? '') + segment.text)
  }

  return [...steps.values()].filter((text) => text.trim() !== '').join('\n\n')
}

/**
 * Render a tool call as the part that holds it.
 */
function toToolPart(call: ChatToolCall): ChatToolPart {
  return {
    type: 'dynamic-tool',
    toolName: call.name,
    toolCallId: call.id,
    state: call.state,
    input: call.arguments,
    output: call.result,
    errorText: call.error,
  }
}

/**
 * Read a tool call back out of its part.
 */
function toToolCall(part: ChatToolPart): ChatToolCall {
  return {
    id: part.toolCallId,
    name: part.toolName,
    arguments: part.input,
    result: part.output,
    state: part.state,
    error: part.errorText,
  }
}

/**
 * Sum the usage of two steps, the way `StreamEnd::combineUsage()` does.
 */
function mergeUsage(current: ChatMessage['usage'], incoming: ChatMessage['usage']): ChatMessage['usage'] {
  if (!incoming) {
    return current
  }

  if (!current) {
    return incoming
  }

  return {
    inputTokens: (current.inputTokens ?? 0) + (incoming.inputTokens ?? 0),
    outputTokens: (current.outputTokens ?? 0) + (incoming.outputTokens ?? 0),
    totalTokens: (current.totalTokens ?? 0) + (incoming.totalTokens ?? 0),
  }
}
