export type AgUiEvent =
  | RunStartedEvent
  | RunFinishedEvent
  | RunErrorEvent
  | RunCancelledEvent
  | StepEvent
  | TextMessageStartEvent
  | TextMessageContentEvent
  | TextMessageEndEvent
  | ReasoningBoundaryEvent
  | ReasoningMessageStartEvent
  | ReasoningMessageContentEvent
  | ReasoningMessageEndEvent
  | ToolCallStartEvent
  | ToolCallArgsEvent
  | ToolCallEndEvent
  | ToolCallResultEvent
  | MessagesSnapshotEvent
  | CustomEvent

export type RunStartedEvent = {
  type: 'RUN_STARTED'
  threadId: string
  runId: string
}

export type RunFinishedEvent = {
  type: 'RUN_FINISHED'
  threadId: string
  runId: string
  messageId?: string
  userMessageId?: string
  usage?: AgUiUsage[]
  metadata?: { finishReason?: string }
  outcome?: { type: 'interrupt'; interrupts: AgUiInterrupt[] }
}

export type AgUiUsage = {
  provider?: string | null
  model?: string | null
  inputTokens?: number | null
  outputTokens?: number | null
  totalTokens?: number | null
  reasoningTokens?: number | null
  cachedInputTokens?: number | null
}

export type AgUiInterrupt = {
  id: string
  reason: string
  message?: string | null
  toolCallId?: string | null
  responseSchema?: Record<string, unknown>
  metadata?: {
    kind?: string
    toolName?: string
    input?: Record<string, unknown>
  }
}

export type RunErrorEvent = {
  type: 'RUN_ERROR'
  message: string
  code?: string | null
}

/**
 * The frame a stopped run ends on, which is what tells a resuming reader it was not merely cut off.
 */
export type RunCancelledEvent = {
  type: 'RUN_CANCELLED'
  threadId: string
  runId: string
}

export type StepEvent = {
  type: 'STEP_STARTED' | 'STEP_FINISHED'
  stepName: string
}

export type TextMessageStartEvent = {
  type: 'TEXT_MESSAGE_START'
  messageId: string
  role: string
  // On a prompt, the id the browser that sent it rendered it under.
  clientMessageId?: string
}

export type TextMessageContentEvent = {
  type: 'TEXT_MESSAGE_CONTENT'
  messageId: string
  delta: string
}

export type TextMessageEndEvent = {
  type: 'TEXT_MESSAGE_END'
  messageId: string
}

export type ReasoningBoundaryEvent = {
  type: 'REASONING_START' | 'REASONING_END'
  messageId: string
}

export type ReasoningMessageStartEvent = {
  type: 'REASONING_MESSAGE_START'
  messageId: string
  role: string
}

export type ReasoningMessageContentEvent = {
  type: 'REASONING_MESSAGE_CONTENT'
  messageId: string
  delta: string
}

export type ReasoningMessageEndEvent = {
  type: 'REASONING_MESSAGE_END'
  messageId: string
}

export type ToolCallStartEvent = {
  type: 'TOOL_CALL_START'
  toolCallId: string
  toolCallName: string
  parentMessageId?: string | null
}

export type ToolCallArgsEvent = {
  type: 'TOOL_CALL_ARGS'
  toolCallId: string
  delta: string
}

export type ToolCallEndEvent = {
  type: 'TOOL_CALL_END'
  toolCallId: string
}

export type ToolCallResultEvent = {
  type: 'TOOL_CALL_RESULT'
  messageId: string
  toolCallId: string
  content: string
  role?: string
  // Absent when the call succeeded.
  metadata?: { error?: string; denied?: boolean }
}

/**
 * The server's own transcript of the thread, which replaces what is rendered rather than folding into it.
 */
export type MessagesSnapshotEvent = {
  type: 'MESSAGES_SNAPSHOT'
  messages: AgUiMessage[]
}

/**
 * AG-UI's own message shape, which is not the one this package renders.
 */
export type AgUiMessage = {
  id: string
  role: 'user' | 'assistant' | 'system' | 'developer' | 'tool'
  content?: string | AgUiContentPart[] | null
  name?: string | null
  toolCallId?: string | null
  toolCalls?: AgUiToolCall[]
  // Set on a tool result that failed or was denied.
  error?: string
  metadata?: { denied?: boolean }
  // An ISO 8601 timestamp, where the server stored one.
  createdAt?: string | null
  // Converse's own: a stored answer's text, reasoning and tool calls in the order they streamed.
  parts?: AgUiStoredPart[]
  // Converse's own: what a stored answer's run reported on `RUN_FINISHED`.
  usage?: AgUiUsage
}

/**
 * One piece of a stored answer, a tool call naming the entry in `toolCalls` it renders and a custom part
 * the `CUSTOM` event it streamed as.
 */
export type AgUiStoredPart =
  | { type: 'text'; text: string }
  | { type: 'reasoning'; text: string }
  | { type: 'tool'; toolCallId: string }
  | { type: 'custom'; name: string; value: Record<string, unknown> }

/**
 * One piece of a message, being either its text or a file it carried.
 */
export type AgUiContentPart = {
  type: string
  text?: string | null
  source?: {
    type: 'url' | 'data'
    value: string
    mimeType?: string | null
  } | null
  metadata?: { filename?: string | null } | null
}

export type AgUiToolCall = {
  id: string
  type?: string
  function: { name: string; arguments: string }
}

export type CustomEvent = {
  type: 'CUSTOM'
  name: string
  value: Record<string, unknown>
}

export type ChatUsage = {
  inputTokens?: number | null
  outputTokens?: number | null
  totalTokens?: number | null
}

export type PendingApproval = {
  id: string
  tool: string
  arguments: Record<string, unknown>
  reason?: string | null
}

export type ChatToolCall = {
  id: string
  name: string
  arguments: Record<string, unknown>
  result: string | null
  state: ChatToolCallState
  error: string | null
}

/**
 * The state of a tool call, where `approval_responded` is the gap between the click and the outcome.
 */
export type ChatToolCallState = 'running' | 'complete' | 'error' | 'denied' | 'awaiting_approval' | 'approval_responded'

export type ChatCitation = { title: string | null; url: string }

export type ChatMessageStatus =
  | 'streaming'
  | 'complete'
  | 'cancelled'
  | 'awaiting_approval'
  | 'approval_responded'
  | 'error'

/**
 * Where a turn is, with `submitted` in flight and `resuming` replaying a durable tail.
 */
export type ChatStatus = 'ready' | 'submitted' | 'streaming' | 'resuming' | 'error'

export type ChatMessage = {
  id: string
  role: 'user' | 'assistant'
  content: string
  reasoning: string
  status: ChatMessageStatus
  toolCalls: ChatToolCall[]
  approvals: PendingApproval[]
  citations: ChatCitation[]
  usage: ChatUsage | null
  error: string | null
  provider: string | null
  model: string | null
  createdAt: string | null
  // Everything that arrived, in order; the fields above are derived from it and it wins where they disagree.
  parts: ChatMessagePart[]
}

/**
 * One piece of a rendered message, discriminated by Vercel's `UIMessagePart` names.
 */
export type ChatMessagePart =
  | ChatTextPart
  | ChatReasoningPart
  | ChatToolPart
  | ChatSourcePart
  | ChatFilePart
  | ChatDataPart

export type ChatTextPart = { type: 'text'; text: string }

export type ChatReasoningPart = { type: 'reasoning'; text: string }

export type ChatToolPart = {
  type: 'dynamic-tool'
  toolName: string
  toolCallId: string
  state: ChatToolCallState
  input: Record<string, unknown>
  output: string | null
  errorText: string | null
}

export type ChatFilePart = {
  type: 'file'
  filename: string
  mediaType: string | null
  url: string | null
}

export type ChatSourcePart = {
  type: 'source-url'
  sourceId: string
  url: string
  title: string | null
}

/**
 * An extension part, where writing the same `id` again replaces it rather than appending.
 */
export type ChatDataPart = {
  type: `data-${string}`
  id: string | null
  data: Record<string, unknown>
}

export type ApprovalDecision = { action: 'approve' } | { action: 'reject' }

/**
 * What the server supports, where an unsupported capability is absent rather than `false`.
 */
export type ChatCapabilities = {
  streaming?: boolean
  approvals?: boolean
  retry?: boolean
  [capability: string]: boolean | undefined
}

/**
 * The same capabilities as questions the markup can ask.
 */
export type ChatAbilities = {
  approve: boolean
  stream: boolean
  retry: boolean
}

export type InfiniteScrollProps = {
  data: string
  reverse: true
  buffer: number
  // A chat always opens on its newest page, so the loaded page stays out of the URL.
  preserveUrl: true
}

/**
 * The server's transcript in AG-UI's shape, either as an array or in Inertia's `ScrollProp` wrapper, ordered newest first.
 */
export type ChatTranscript = AgUiMessage[] | { data: AgUiMessage[] }

/**
 * Everything the server puts in the page for a chat to mount on.
 */
export type ChatPayload = {
  threadId: string | null
  // The thread's stream position the transcript was rendered at, which a chat follows the thread from.
  offset?: number | null
  endpoint: string
  // The stream route with `:thread` standing in for the thread id.
  streamEndpoint?: string | null
  messages: ChatTranscript
  // The approvals the thread's last run paused on.
  interrupts?: AgUiInterrupt[]
  // Where the transcript lives in the page props when it is paged, e.g. `chat.messages`.
  transcriptPath: string | null
  capabilities: ChatCapabilities
}

/**
 * A run on a thread, which is what a cancel names.
 */
export type ChatRunRef = {
  threadId: string
  runId: string
}

/**
 * A turn the server accepted: the run answering it, and the stream position its events begin after.
 */
export type ChatAcceptedTurn = ChatRunRef & {
  offset: number
}

/**
 * How far through the thread's stream this browser has read.
 */
export type ChatSession = {
  threadId: string
  offset: number
}

/**
 * Where a read of the thread's stream caught up to, and whether a run on the thread is still queued or running.
 */
export type ChatStreamPosition = {
  offset: number
  live: boolean
}

/**
 * The sentinel a read of the thread's stream ends on, sent as a `converse-stream-end` event.
 */
export type ChatStreamEnd = {
  reason: 'up-to-date'
  offset: number | string
  live: boolean
  poll?: number
}

export type ChatStreamFrame = {
  event: string | null
  id: string | null
  data: string
}

/**
 * Sending a turn. A transport that streams the answer itself hands it to `onEvent` and resolves once the turn
 * is over; one whose answer arrives on the thread's stream resolves with the turn the server accepted.
 */
export type ChatConnectionRequest = {
  body: Record<string, unknown>
  signal: AbortSignal
  onEvent: (event: AgUiEvent, frame?: ChatStreamFrame) => void
}

/**
 * Following a thread's stream from an offset, which carries on until the signal is aborted.
 */
export type ChatAttachRequest = {
  threadId: string
  offset: number
  signal: AbortSignal
  onEvent: (event: AgUiEvent, frame?: ChatStreamFrame) => void
  onUpToDate?: (position: ChatStreamPosition) => void
}

/**
 * A transport, with an optional `attach` for the ones that can follow a thread, which one that resolves with an
 * accepted turn needs, and `cancel` for the ones that can stop a run on the server rather than only on the wire.
 */
export type ChatConnection = ((request: ChatConnectionRequest) => Promise<ChatAcceptedTurn | void>) & {
  attach?: (request: ChatAttachRequest) => Promise<void>
  cancel?: (request: ChatRunRef) => Promise<void>
}

/**
 * What becomes of a message sent while the thread is answering, where `interrupt` stops that run first.
 */
export type ChatQueue = 'queue' | 'drop' | 'interrupt'

/**
 * A message waiting for the run on the wire, whose id is the user message it rendered as.
 */
export type ChatQueuedMessage = {
  id: string
  content: string
}

/**
 * What a chat mounts on, of which a bare `{ endpoint }` is the smallest valid payload.
 */
export type ChatSource = Pick<ChatPayload, 'endpoint'> & Partial<Omit<ChatPayload, 'endpoint'>>
