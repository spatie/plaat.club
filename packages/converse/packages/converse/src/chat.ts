import { ChatRequestError, ChatThreadBusyError } from './errors'
import { createUserMessage, StreamedMessage } from './message'
import { runAgentInput, type TurnFields } from './runAgentInput'
import { frameSequence, SessionTracker } from './session'
import { pausedMessage, Transcript, type LiveTurn } from './transcript'
import type {
  AgUiEvent,
  ApprovalDecision,
  ChatAbilities,
  ChatAcceptedTurn,
  ChatConnection,
  ChatMessage,
  ChatQueue,
  ChatQueuedMessage,
  ChatRunRef,
  ChatSource,
  ChatSession,
  ChatStatus,
  ChatStreamFrame,
  ChatStreamPosition,
  InfiniteScrollProps,
  MessagesSnapshotEvent,
  PendingApproval,
  RunErrorEvent,
  RunFinishedEvent,
  TextMessageStartEvent,
} from './types'

/**
 * The state a chat renders from.
 */
export type ChatState = {
  messages: ChatMessage[]
  threadId: string | null
  session: ChatSession | null
  status: ChatStatus
  streaming: boolean
  // Streaming or catching up, either of which queues the next message.
  busy: boolean
  error: string | null
  errorCode: string | null
  validationErrors: Record<string, string[]>
  pendingApprovals: PendingApproval[]
  queued: ChatQueuedMessage[]
  can: ChatAbilities
  infiniteScrollProps: InfiniteScrollProps | null
}

/**
 * The configuration a chat store is created with.
 */
export type ChatStoreConfig = {
  source: () => ChatSource
  connection: ChatConnection
  history?: () => ChatMessage[] | undefined
  queue?: ChatQueue | (() => ChatQueue | undefined)
  scope?: string
  onEvent?: (event: AgUiEvent) => void
  onFinish?: (message: ChatMessage) => void
  onError?: (error: Error) => void
  onSessionChange?: (session: ChatSession | null) => void
}

/**
 * The chat store, as a framework adapter consumes it.
 */
export type ChatStore = {
  getState: () => ChatState
  subscribe: (listener: () => void) => () => void
  syncSource: () => void
  mount: () => () => void
  send: (message: string) => Promise<boolean>
  respond: (decisions: Record<string, ApprovalDecision>) => Promise<void>
  approve: (toolCallId: string) => Promise<void>
  reject: (toolCallId: string) => Promise<void>
  retry: () => Promise<void>
  attach: () => Promise<void>
  sync: () => Promise<void>
  cancel: () => Promise<void>
  detach: () => void
  clearError: () => void
}

/**
 * The options one run is started with.
 */
type RunOptions = {
  respondingTo?: string
  resolving?: string[]
}

/**
 * A turn waiting for the run on the wire to finish, being a message or the reply to a pause.
 */
type QueuedTurn = {
  turn: LiveTurn
  fields: TurnFields
  options: RunOptions
}

/**
 * The run a retry replays.
 */
type Run = {
  fields: TurnFields
  turn: LiveTurn
  options: RunOptions
}

/**
 * What a run puts on screen, whichever browser sent it.
 */
type RunRender = {
  turn: LiveTurn
  answer: StreamedMessage
  resolving: Set<string>
  snapshot: boolean
  prompts: Map<string, ChatMessage | null>
  storedReplies: Set<string>
  // The highest frame applied, for a transport that streams the turn itself.
  lastSequence: number
}

/**
 * Where this browser's own turn is: sent and not yet answered for, streamed by the transport itself, accepted by the
 * server to be read off the thread's stream, or ended there and not yet unwound.
 */
type LocalRunState =
  | { kind: 'submitting'; waiting: ((run: ChatRunRef | null) => void)[] }
  | { kind: 'inline'; run: ChatRunRef }
  | {
      kind: 'accepted'
      run: ChatRunRef
      // Where the run's events begin in the thread's stream.
      offset: number
      // Ends the wait for the run to settle on the thread's stream, with the failure that ended it where one did.
      settle: (failure?: unknown) => void
    }
  | { kind: 'settled'; run: ChatRunRef }

/**
 * This browser's own turn.
 */
type LocalRun = {
  render: RunRender
  controller: AbortController
  state: LocalRunState
}

/**
 * A run the thread's stream is showing that this browser did not send, named once the stream or a refusal says which.
 */
type ObservedRun = {
  render: RunRender
  run: ChatRunRef | null
}

/**
 * What a read of the thread's stream delivered: an event, where it caught up to, or its own end.
 */
type Delivery =
  | { kind: 'event'; event: AgUiEvent; frame?: ChatStreamFrame }
  | { kind: 'position'; position: ChatStreamPosition }
  | { kind: 'end'; thrown: unknown }

/**
 * The current read of the thread's stream, until it is aborted or its end, whether caught up with nothing live or
 * failed, has been applied.
 */
type Follower = {
  threadId: string
  controller: AbortController
  caughtUp: Promise<void>
  markCaughtUp: () => void
  // False once the read ended, while its end waits behind what it delivered.
  reading: boolean
  // What it delivered while a send waited to learn where its own run begins, in the order it arrived.
  backlog: Delivery[]
}

/**
 * The events a run ends on.
 */
const TERMINAL_EVENTS = ['RUN_FINISHED', 'RUN_ERROR', 'RUN_CANCELLED']

/**
 * The frames that arrive many times per answer, rather than once per run.
 */
const TOKEN_DELTAS = ['TEXT_MESSAGE_CONTENT', 'REASONING_MESSAGE_CONTENT', 'TOOL_CALL_ARGS']

/**
 * Determine if the event is one of the streamed token deltas.
 */
function isTokenDelta(event: AgUiEvent): boolean {
  return TOKEN_DELTAS.includes(event.type)
}

/**
 * How far above the transcript the next page of messages is fetched.
 */
const SCROLL_BUFFER = 250

/**
 * The chat state machine, with no framework in it.
 */
export class ChatEngine implements ChatStore {
  protected listeners = new Set<() => void>()

  protected status: ChatStatus = 'ready'

  protected error: string | null = null

  protected errorCode: string | null = null

  protected validationErrors: Record<string, string[]> = {}

  protected revision = 0

  protected active: LocalRun | null = null

  protected observed: ObservedRun | null = null

  protected follower: Follower | null = null

  protected lastSettledRunId: string | null = null

  protected mounted = false

  protected notifying = false

  protected snapshot: ChatState | null = null

  protected snapshotKey: unknown[] = []

  protected serverThreadId: string | null

  protected session: SessionTracker

  protected threadId: string | null

  protected transcript: Transcript

  protected pending: QueuedTurn[] = []

  protected lastRun: Run | null = null

  protected sequence = 0

  // Not `crypto.randomUUID()`, which a page served over plain http does not have.
  protected storeId = Math.random().toString(36).slice(2, 10)

  protected cancelling: { runId: string; request: Promise<void> } | null = null

  /**
   * Create a new chat store.
   */
  constructor(protected config: ChatStoreConfig) {
    this.transcript = new Transcript(config)
    this.serverThreadId = config.source().threadId ?? null

    // A moved session is a moved state, which the next snapshot is built afresh for.
    this.session = new SessionTracker(this.serverThreadId, config.source().offset, (session) => {
      this.revision += 1
      config.onSessionChange?.(session)
    })

    const session = this.session.current()

    this.threadId = session?.threadId ?? this.serverThreadId
    this.status = session === null ? 'ready' : 'resuming'

    // An adapter is free to pull a single callable out of the store, so the methods keep their receiver.
    this.getState = this.getState.bind(this)
    this.subscribe = this.subscribe.bind(this)
    this.syncSource = this.syncSource.bind(this)
    this.mount = this.mount.bind(this)
    this.send = this.send.bind(this)
    this.respond = this.respond.bind(this)
    this.approve = this.approve.bind(this)
    this.reject = this.reject.bind(this)
    this.retry = this.retry.bind(this)
    this.attach = this.attach.bind(this)
    this.sync = this.sync.bind(this)
    this.cancel = this.cancel.bind(this)
    this.detach = this.detach.bind(this)
    this.clearError = this.clearError.bind(this)
  }

  /**
   * Get the current state, reusing the last snapshot while nothing it was built from has moved.
   */
  public getState(): ChatState {
    // The payload is read on every call, so that a reactive adapter still tracks it.
    const source = this.config.source()
    const history = this.config.history?.()
    const key = [
      this.revision,
      source.threadId,
      source.messages,
      source.interrupts,
      source.transcriptPath,
      source.capabilities,
      history,
    ]

    if (this.snapshot !== null && key.every((value, index) => Object.is(value, this.snapshotKey[index]))) {
      return this.snapshot
    }

    const messages = this.messages(history)
    const path = source.transcriptPath ?? null

    this.snapshotKey = key

    this.snapshot = {
      messages,
      threadId: this.threadId,
      session: this.session.current(),
      status: this.status,
      streaming: this.isStreaming(),
      busy: this.isBusy(),
      error: this.error,
      errorCode: this.errorCode,
      validationErrors: this.validationErrors,
      pendingApprovals: this.status === 'resuming' ? [] : (pausedMessage(messages)?.approvals ?? []),
      queued: this.pending.flatMap(({ fields }) =>
        'message' in fields ? [{ id: fields.messageId, content: fields.message }] : [],
      ),
      can: this.abilities(messages),
      infiniteScrollProps:
        path === null ? null : { data: path, reverse: true, buffer: SCROLL_BUFFER, preserveUrl: true },
    }

    return this.snapshot
  }

  /**
   * Register a listener for state changes.
   */
  public subscribe(listener: () => void): () => void {
    this.listeners.add(listener)

    return () => {
      this.listeners.delete(listener)
    }
  }

  /**
   * Drop the local state when the payload names a different thread, following the new one where it can.
   */
  public syncSource(): void {
    const id = this.config.source().threadId ?? null

    if (id === this.serverThreadId) {
      return
    }

    this.serverThreadId = id

    if (id === this.threadId) {
      return
    }

    this.detach()
    this.forgetLocalState()
    this.threadId = id
    this.notify()

    if (this.mounted) {
      void this.attach()
    }
  }

  /**
   * Follow the thread the page named, and get the teardown that stops reading it.
   */
  public mount(): () => void {
    // Guarded on `window`, since a component's setup also runs on the server, where nothing can read the thread.
    if (typeof window !== 'undefined') {
      this.mounted = true

      void this.attach()
    }

    // A component going away stops reading the thread, it does not stop the run on it.
    return () => {
      this.mounted = false
      this.detach()
    }
  }

  /**
   * Send a message, and determine if the store kept it, which a busy thread under `drop` does not.
   */
  public async send(message: string): Promise<boolean> {
    const content = message.trim()

    if (content === '') {
      return false
    }

    const messageId = this.localId('user')
    const turn = this.transcript.openTurn()

    turn.messages = [createUserMessage(messageId, content)]

    const fields: TurnFields = { message: content, messageId }

    if (!this.isBusy() && this.pending.length === 0) {
      this.notify()

      return this.run(fields, turn)
    }

    if (!this.isBusy()) {
      this.pending = [...this.pending, { turn, fields, options: {} }]
      this.clearError()

      return true
    }

    const whenBusy = this.queueBehavior()

    if (whenBusy === 'drop') {
      this.transcript.discardTurn(turn)
      this.notify()

      return false
    }

    this.pending = [...this.pending, { turn, fields, options: {} }]
    this.notify()

    if (whenBusy === 'interrupt') {
      await this.cancel()
    }

    return true
  }

  /**
   * Answer the approvals the paused turn is waiting on.
   */
  public async respond(decisions: Record<string, ApprovalDecision>): Promise<void> {
    if (this.isBusy() || Object.keys(decisions).length === 0) {
      return
    }

    const paused = pausedMessage(this.messages())

    // Read before `answered` hides them, since the resumed run reports results for these calls.
    const resolving = paused?.approvals ?? []

    if (paused !== null) {
      this.transcript.markAnswered(paused.id)
    }

    await this.run({ decisions }, this.transcript.openTurn(), {
      respondingTo: paused?.id,
      resolving: resolving.map((approval) => approval.id),
    })
  }

  /**
   * Approve a paused tool call.
   */
  public approve(toolCallId: string): Promise<void> {
    return this.respond({ [toolCallId]: { action: 'approve' } })
  }

  /**
   * Reject a paused tool call.
   */
  public reject(toolCallId: string): Promise<void> {
    return this.respond({ [toolCallId]: { action: 'reject' } })
  }

  /**
   * Replay the turn that never came back with an answer.
   */
  public async retry(): Promise<void> {
    if (this.isBusy()) {
      return
    }

    const run = this.retryableRun()

    if (run !== null) {
      run.turn.messages = run.turn.messages.filter((message) => message.role !== 'assistant')

      await this.run(run.fields, run.turn, run.options)

      return
    }

    const prompt = unansweredPrompt(this.messages())

    if (prompt === null) {
      return
    }

    this.transcript.discardStoppedAnswers()

    // The prompt is on screen already, stored or off the thread's stream, so the turn carries the answer alone.
    await this.run({ message: prompt.content, messageId: prompt.id }, this.transcript.openTurn())
  }

  /**
   * Follow the thread's stream, and wait until the read has caught up with it.
   */
  public attach(): Promise<void> {
    // A turn this browser is streaming itself reads the thread as it goes, unless it is being abandoned.
    if (this.active !== null && !this.active.controller.signal.aborted) {
      return Promise.resolve()
    }

    const source = this.config.source()

    if (typeof source.threadId === 'string' && typeof source.offset === 'number') {
      this.session.begin(source.threadId, source.offset)
    }

    const session = this.session.current()
    const attach = this.config.connection.attach

    // A page that named an offset mounted the store in `resuming`, so the status is let go of even with nothing to read.
    if (attach === undefined || session === null) {
      this.settleResuming()

      return Promise.resolve()
    }

    // Catching up holds approvals and sends back until the read says whether a run is live.
    if (this.follower?.threadId !== session.threadId && this.status === 'ready' && this.active === null) {
      this.status = 'resuming'
      this.notify()
    }

    return this.follow(attach, session)
  }

  /**
   * Catch up on the thread from where this browser read to, following it for as long as a run on it is live.
   */
  public sync(): Promise<void> {
    const session = this.session.current()
    const attach = this.config.connection.attach

    if (attach === undefined || session === null) {
      return Promise.resolve()
    }

    // A read already in flight, this browser's own turn's included, is catching up on the thread already.
    if (this.follower !== null) {
      return this.follower.caughtUp
    }

    // A send not yet accepted follows the thread itself once it is.
    if (this.active !== null) {
      return Promise.resolve()
    }

    return this.follow(attach, session)
  }

  /**
   * Stop the run, on the server as well as on the wire.
   */
  public async cancel(): Promise<void> {
    const active = this.active
    const cancel = this.config.connection.cancel

    // Another tab's run is stopped the same way, where its stream has named it.
    if (active === null) {
      const observed = this.observed?.run ?? null

      if (cancel !== undefined && observed !== null) {
        await this.requestCancel(cancel, observed)
      }

      return
    }

    // A transport with nowhere to send a durable stop can only drop the read.
    if (cancel === undefined) {
      active.controller.abort()

      return
    }

    const run = await this.acceptedRun(active)

    // The send was refused, or the run ended while the cancel waited to learn which run to name.
    if (run === null || !this.isLive(active)) {
      return
    }

    await this.requestCancel(cancel, run)
  }

  /**
   * Stop reading the thread and the turn on the wire, leaving the server to finish the run.
   */
  public detach(): void {
    this.active?.controller.abort()
    this.stopFollowing()
  }

  /**
   * Clear the error the last run reported.
   */
  public clearError(): void {
    this.clearErrors()

    if (this.status === 'error') {
      this.status = 'ready'
      void this.drain()
    }

    this.notify()
  }

  /**
   * Open a run for the turn, and determine if the store kept the turn.
   */
  protected async run(input: TurnFields, turn: LiveTurn, options: RunOptions = {}): Promise<boolean> {
    const active: LocalRun = {
      render: this.renderRun(turn, options.resolving),
      controller: new AbortController(),
      state: { kind: 'submitting', waiting: [] },
    }

    this.clearErrors()
    this.status = 'submitted'
    this.active = active
    this.lastRun = { fields: input, turn, options }

    turn.messages = [...turn.messages, active.render.answer.message]

    this.notify()

    try {
      await this.transport(input, active)
      await this.finishRun(active)
    } catch (thrown) {
      if (thrown instanceof ChatThreadBusyError && this.isLive(active)) {
        return this.rejoin(thrown, input, active, options)
      }

      this.handleFailure(thrown, active, options.respondingTo)
    } finally {
      // A send that never reported a run leaves a cancel waiting for one, so the unwind lets it go.
      this.releaseWaiting(active)

      // By identity, since an interrupt puts its replacement on the wire before this run unwinds.
      if (this.isLive(active)) {
        this.active = null
      }

      this.transcript.discardIfEmpty(turn)
      this.releaseHeld()
    }

    return true
  }

  /**
   * Wait for the run that holds the thread instead, keeping the undelivered turn the way `queue` says a busy thread does.
   */
  protected rejoin(busy: ChatThreadBusyError, fields: TurnFields, active: LocalRun, options: RunOptions): boolean {
    const attach = this.config.connection.attach

    if (attach === undefined) {
      this.answerAgain(options.respondingTo)
      this.handleFailure(busy, active)

      return true
    }

    const whenBusy = this.queueBehavior()
    const turn = whenBusy === 'drop' ? null : this.waitingTurn(fields, active)

    this.lastRun = null

    if (turn === null) {
      active.render.turn.messages = []
      this.answerAgain(options.respondingTo)
    } else {
      this.pending = [{ turn, fields, options }, ...this.pending]
    }

    const session = this.session.begin(busy.threadId, busy.offset)

    this.threadId = busy.threadId

    // The run may have settled on the stream before the refusal arrived, which leaves nothing to wait for.
    const settled = this.lastSettledRunId === busy.runId
    const observed = settled ? null : (this.observed ?? this.observe())

    if (observed !== null && observed.run === null) {
      observed.run = { threadId: busy.threadId, runId: busy.runId }
    }

    // Behind the run it waits for, so the prompt renders after that run's answer.
    if (turn !== null) {
      this.transcript.moveToEnd(turn)
    }

    this.status = settled ? 'ready' : 'streaming'
    this.notify()

    void this.follow(attach, session)

    const cancel = this.config.connection.cancel

    if (turn !== null && whenBusy === 'interrupt' && cancel !== undefined) {
      void this.requestCancel(cancel, busy)
    }

    // Once this run has unwound, which is what lets the next one on the wire.
    if (settled) {
      queueMicrotask(() => void this.drain())
    }

    return turn !== null
  }

  /**
   * Get the turn a refused send waits in, with the answer it never got taken off it.
   */
  protected waitingTurn(fields: TurnFields, active: LocalRun): LiveTurn {
    const turn = active.render.turn

    // A reply puts nothing on screen until it is sent, so it waits in a turn of its own that outlives the refused one.
    if (!('message' in fields)) {
      turn.messages = []

      return this.transcript.openTurn()
    }

    turn.messages = turn.messages.filter((message) => message !== active.render.answer.message)

    return turn
  }

  /**
   * Show the approvals a reply that never reached the server was answering again, so they can be answered again.
   */
  protected answerAgain(messageId: string | undefined): void {
    if (messageId !== undefined) {
      this.transcript.unmarkAnswered(messageId)
    }
  }

  /**
   * Report a run that ended cleanly, then send the next queued turn.
   */
  protected async finishRun(active: LocalRun): Promise<void> {
    // Only the live run may report `ready`, or the store looks idle while a replacement is streaming.
    if (!this.isLive(active) || this.status === 'error') {
      return
    }

    this.status = 'ready'
    this.notify()

    if (active.render.turn.messages.includes(active.render.answer.message)) {
      this.config.onFinish?.(active.render.answer.message)
    }

    await this.drain()
  }

  /**
   * Send the turn, and wait for its run to settle on the thread's stream where the transport did not stream it.
   */
  protected async transport(input: TurnFields, active: LocalRun): Promise<void> {
    const accepted = await this.config.connection({
      signal: active.controller.signal,
      body: runAgentInput(input, { threadId: this.threadId }),
      onEvent: (event, frame) => {
        if (this.isFresh(frame, active.render)) {
          this.handleEvent(event, active)
        }
      },
    })

    // A transport that streamed the turn itself has nothing left to wait for.
    if (accepted === undefined) {
      return
    }

    const attach = this.config.connection.attach

    if (attach === undefined) {
      throw new Error('The chat connection accepted the turn, but has no attach to read its answer with.')
    }

    const session = this.session.begin(accepted.threadId, accepted.offset)
    const settled = this.recordAccepted(accepted, active)

    void this.follow(attach, session)
    this.releaseHeld()

    await settled
  }

  /**
   * Record the run the server accepted the turn as, and get the wait for it to settle on the thread's stream or be
   * abandoned.
   */
  protected recordAccepted(accepted: ChatAcceptedTurn, active: LocalRun): Promise<void> {
    const signal = active.controller.signal

    const settled = new Promise<void>((resolve, reject) => {
      const settle = (failure?: unknown): void => (failure === undefined ? resolve() : reject(failure))

      this.moveRun(active, {
        kind: 'accepted',
        run: { threadId: accepted.threadId, runId: accepted.runId },
        offset: accepted.offset,
        settle,
      })

      if (signal.aborted) {
        settle(signal.reason)

        return
      }

      signal.addEventListener('abort', () => settle(signal.reason), { once: true })
    })

    this.threadId = accepted.threadId
    this.notify()

    return settled
  }

  /**
   * Follow the thread's stream, unless it is being followed already, and wait until the read has caught up.
   */
  protected follow(attach: NonNullable<ChatConnection['attach']>, session: ChatSession): Promise<void> {
    const threadId = session.threadId
    const previous = this.follower

    if (previous?.threadId === threadId && previous.reading) {
      return previous.caughtUp
    }

    // A read of this thread that ended while what it delivered was held hands that on to the read after it.
    const continues = previous?.threadId === threadId

    if (!continues) {
      this.stopFollowing()
    }

    let markCaughtUp: (() => void) | null = null

    const follower: Follower = {
      threadId,
      controller: new AbortController(),
      caughtUp: new Promise((resolve) => (markCaughtUp = resolve)),
      markCaughtUp: () => markCaughtUp?.(),
      reading: true,
      backlog: continues ? previous.backlog.filter((delivery) => delivery.kind !== 'end') : [],
    }

    this.follower = follower
    this.threadId = threadId

    attach({
      threadId,
      offset: session.offset,
      signal: follower.controller.signal,
      onEvent: (event, frame) => this.handleFollowedEvent(event, follower, frame),
      onUpToDate: (position) => this.handleUpToDate(position, follower),
    }).then(
      () => this.endFollowing(follower, null),
      (thrown: unknown) => this.endFollowing(follower, thrown),
    )

    return follower.caughtUp
  }

  /**
   * Stop reading the thread's stream, leaving whatever run it was showing where it got to.
   */
  protected stopFollowing(): void {
    const follower = this.follower

    if (follower === null) {
      return
    }

    this.follower = null

    follower.controller.abort()
    follower.markCaughtUp()

    this.dropObserved()
  }

  /**
   * Handle the follow ending on its own, which a failure does and a transport with no stream to keep open may.
   */
  protected endFollowing(follower: Follower, thrown: unknown): void {
    if (this.follower !== follower) {
      return
    }

    follower.reading = false
    follower.markCaughtUp()

    this.deliver(follower, { kind: 'end', thrown })
  }

  /**
   * Let go of a follow that ended, once everything it delivered before its end has been applied.
   */
  protected finishFollowing(thrown: unknown): void {
    this.follower = null

    if (thrown !== null) {
      this.failFollowing(thrown)

      return
    }

    // A stream that ended has nothing further to say about any run on it.
    this.settleActive()
    this.settleShown()
  }

  /**
   * Fail whatever was waiting on the thread's stream, reporting it to the store where no turn of this browser's was.
   */
  protected failFollowing(thrown: unknown): void {
    const failure = thrown instanceof Error ? thrown : new Error('The chat stream failed.')

    if (this.active !== null) {
      this.settleActive(failure)

      return
    }

    this.observed?.render.answer.apply({ type: 'RUN_ERROR', message: failure.message })
    this.observed = null

    this.fail(failure, failure instanceof ChatRequestError ? failure.code : null)
  }

  /**
   * Apply an event from the thread's stream to the run it belongs to, which the transport hands over once each.
   */
  protected handleFollowedEvent(event: AgUiEvent, follower: Follower, frame?: ChatStreamFrame): void {
    if (this.follower !== follower) {
      return
    }

    if (event.type === 'MESSAGES_SNAPSHOT') {
      this.session.restartAt(frame)
    } else {
      this.session.advance(frame)
    }

    this.deliver(follower, { kind: 'event', event, frame })
  }

  /**
   * Move the session to where the read caught up, settling a run the server says is no longer live.
   */
  protected handleUpToDate(position: ChatStreamPosition, follower: Follower): void {
    if (this.follower !== follower) {
      return
    }

    this.session.catchUp(position.offset)
    follower.markCaughtUp()

    this.deliver(follower, { kind: 'position', position })
  }

  /**
   * Apply what the follower delivered, behind whatever it delivered before that is still held.
   */
  protected deliver(follower: Follower, delivery: Delivery): void {
    follower.backlog = [...follower.backlog, delivery]

    this.releaseHeld()
  }

  /**
   * Apply what the current follower holds, for as long as it is current and it is clear which run each event belongs to.
   */
  protected releaseHeld(): void {
    const follower = this.follower

    if (follower === null) {
      return
    }

    while (this.follower === follower && !this.isHolding() && follower.backlog.length > 0) {
      const [delivery, ...rest] = follower.backlog

      follower.backlog = rest

      this.applyDelivery(delivery)
    }
  }

  /**
   * Determine if the thread's stream must wait, as it does while a send not yet accepted cannot tell its own run's
   * events from another's, and while a run that ended on it has yet to unwind.
   */
  protected isHolding(): boolean {
    return this.active !== null && this.active.state.kind !== 'accepted'
  }

  /**
   * Apply one thing a read of the thread's stream delivered.
   */
  protected applyDelivery(delivery: Delivery): void {
    switch (delivery.kind) {
      case 'event':
        this.routeEvent(delivery.event, delivery.frame)

        return
      case 'position':
        this.applyPosition(delivery.position)

        return
      case 'end':
        this.finishFollowing(delivery.thrown)
    }
  }

  /**
   * Apply an event to the run it belongs to: this browser's own past where its events begin, any other otherwise.
   */
  protected routeEvent(event: AgUiEvent, frame?: ChatStreamFrame): void {
    const state = this.active?.state
    const sequence = frameSequence(frame)

    if (this.active !== null && state?.kind === 'accepted' && (sequence === null || sequence > state.offset)) {
      this.handleEvent(event, this.active)

      if (TERMINAL_EVENTS.includes(event.type)) {
        this.moveRun(this.active, { kind: 'settled', run: state.run })
        state.settle()
      }

      return
    }

    const observed = this.observed ?? this.observe()

    this.handleEvent(event, observed)

    if (TERMINAL_EVENTS.includes(event.type)) {
      this.settleObserved(observed)
    }
  }

  /**
   * Apply where a read caught up to: a live run is waited for, and none being live settles the one on screen.
   */
  protected applyPosition(position: ChatStreamPosition): void {
    if (!position.live) {
      this.settleShown()

      return
    }

    // A run another tab queued may not have written anything yet, but it holds the thread all the same.
    if (this.active === null && this.observed === null) {
      this.observe()
    }

    if (this.status === 'resuming' || (this.status === 'ready' && this.active === null)) {
      this.status = 'streaming'
      this.notify()
    }
  }

  /**
   * Settle the run the thread's stream is showing, or let go of resuming where it shows none.
   */
  protected settleShown(): void {
    if (this.observed !== null) {
      this.settleObserved(this.observed)

      return
    }

    this.settleResuming()
  }

  /**
   * Start rendering a run this browser did not send.
   */
  protected observe(): ObservedRun {
    const observed: ObservedRun = { render: this.renderRun(this.transcript.openTurn()), run: null }

    this.observed = observed

    // Its events came before this browser's own turn and whatever is queued, so those render after it.
    for (const turn of [this.active?.render.turn, ...this.pending.map((entry) => entry.turn)]) {
      if (turn !== undefined) {
        this.transcript.moveToEnd(turn)
      }
    }

    return observed
  }

  /**
   * Let go of a run this browser did not send once it has settled, then send whatever was waiting for it.
   */
  protected settleObserved(observed: ObservedRun): void {
    if (this.observed !== observed) {
      return
    }

    const render = observed.render

    this.observed = null
    this.lastSettledRunId = observed.run?.runId ?? null

    this.transcript.discardIfEmpty(render.turn)

    if (this.active !== null || this.status === 'error') {
      this.notify()

      return
    }

    this.status = 'ready'
    this.notify()

    if (render.turn.messages.includes(render.answer.message)) {
      this.config.onFinish?.(render.answer.message)
    }

    void this.drain()
  }

  /**
   * Forget the run this browser did not send, as one that stops reading the thread does.
   */
  protected dropObserved(): void {
    const observed = this.observed

    if (observed === null) {
      return
    }

    this.observed = null

    this.transcript.discardIfEmpty(observed.render.turn)

    if (this.active === null && this.isBusy()) {
      this.status = 'ready'
    }

    this.notify()
  }

  /**
   * Create the state a run is rendered with.
   */
  protected renderRun(turn: LiveTurn, resolving: string[] = []): RunRender {
    return {
      turn,
      answer: new StreamedMessage(this.localId('assistant')),
      resolving: new Set(resolving),
      snapshot: false,
      prompts: new Map(),
      storedReplies: new Set(),
      lastSequence: -1,
    }
  }

  /**
   * Apply one event from the run to what is on screen.
   */
  protected handleEvent(event: AgUiEvent, owner: LocalRun | ObservedRun): void {
    const render = owner.render

    if (
      this.status === 'submitted' ||
      this.status === 'resuming' ||
      (this.status === 'ready' && owner === this.observed)
    ) {
      this.status = 'streaming'
    }

    if (event.type === 'MESSAGES_SNAPSHOT') {
      this.applySnapshot(event, render)

      return
    }

    if (this.handlePromptFrame(event, render) || this.isStoredReply(event, render)) {
      return
    }

    if (event.type === 'RUN_FINISHED') {
      this.adoptStoredIds(event.messageId, event.userMessageId, render)

      if (this.finishSnapshot(event, render)) {
        return
      }
    }

    if (event.type === 'RUN_STARTED') {
      this.threadId = event.threadId
      this.recordRun({ threadId: event.threadId, runId: event.runId }, owner)
      this.notify()

      return
    }

    if (event.type === 'RUN_ERROR') {
      this.recordRunError(event, owner)

      return
    }

    if (render.answer.startsAnotherMessage(event)) {
      this.startAnotherAnswer(render)
    }

    if (this.resolvesPausedCall(event, owner)) {
      this.recordResolvedCall(event)
    } else {
      render.answer.apply(event)
      this.revealAnswer(render)
    }

    this.config.onEvent?.(event)

    if (isTokenDelta(event)) {
      this.notifyStreamed()

      return
    }

    this.notify()
  }

  /**
   * Fail the answer the way a refused request does, so the queue stops behind it.
   */
  protected recordRunError(event: RunErrorEvent, owner: LocalRun | ObservedRun): void {
    owner.render.answer.apply(event)
    this.revealAnswer(owner.render)
    this.config.onEvent?.(event)

    // Another tab's run speaks for the store only while this browser has no turn of its own on the wire.
    if (!this.isLive(owner) && (owner !== this.observed || this.active !== null)) {
      return
    }

    this.fail(new Error(event.message), event.code ?? null)
  }

  /**
   * Replace what is rendered with the server's own transcript of the thread.
   */
  protected applySnapshot(event: MessagesSnapshotEvent, render: RunRender): void {
    render.snapshot = true

    const kept = [render.turn, this.active?.render.turn, ...this.pending.map((entry) => entry.turn)]

    this.transcript.adoptSnapshot(
      event.messages,
      kept.filter((turn) => turn !== undefined),
    )

    render.turn.messages = []
    render.prompts.clear()
    render.storedReplies.clear()
    render.answer = new StreamedMessage(this.localId('assistant'))

    this.notify()
  }

  /**
   * Fold the outcome that closes a snapshot into the stored message that announced the call.
   */
  protected finishSnapshot(event: RunFinishedEvent, render: RunRender): boolean {
    if (!render.snapshot || !this.transcript.hasSnapshot() || rendersSomething(render.answer.message)) {
      return false
    }

    this.transcript.closeSnapshot(event.outcome?.interrupts ?? [])

    this.config.onEvent?.(event)
    this.notify()

    return true
  }

  /**
   * Render the prompt frames the stream carries, keeping them out of the answer.
   */
  protected handlePromptFrame(event: AgUiEvent, render: RunRender): boolean {
    if (event.type === 'TEXT_MESSAGE_START' && event.role === 'user') {
      this.recordPrompt(event, render)
      this.notify()

      return true
    }

    if (event.type !== 'TEXT_MESSAGE_CONTENT' && event.type !== 'TEXT_MESSAGE_END') {
      return false
    }

    if (!render.prompts.has(event.messageId)) {
      return false
    }

    if (event.type === 'TEXT_MESSAGE_CONTENT') {
      this.appendPrompt(event.messageId, event.delta, render)
      this.notifyStreamed()
    }

    return true
  }

  /**
   * Determine if the frame replays a reply the transcript already carries, as a handler stores each send as it goes.
   */
  protected isStoredReply(event: AgUiEvent, render: RunRender): boolean {
    if (
      event.type !== 'TEXT_MESSAGE_START' &&
      event.type !== 'TEXT_MESSAGE_CONTENT' &&
      event.type !== 'TEXT_MESSAGE_END'
    ) {
      return false
    }

    if (
      event.type === 'TEXT_MESSAGE_START' &&
      this.transcript.stored().some((message) => message.id === event.messageId)
    ) {
      render.storedReplies.add(event.messageId)
    }

    return render.storedReplies.has(event.messageId)
  }

  /**
   * Record a prompt the stream named, rendering it where neither the transcript nor this browser's own turn has it.
   */
  protected recordPrompt(event: TextMessageStartEvent, render: RunRender): void {
    if (render.prompts.has(event.messageId)) {
      return
    }

    // The sender's own prompt comes back under the stored id, naming the one this browser rendered it as.
    const ids = event.clientMessageId === undefined ? [event.messageId] : [event.messageId, event.clientMessageId]
    const message = this.transcript.carries(ids) ? null : createUserMessage(event.messageId, '')

    render.prompts.set(event.messageId, message)

    if (message !== null) {
      render.turn.messages = [...render.turn.messages, message]
    }
  }

  /**
   * Append a delta to a prompt this run is rendering.
   */
  protected appendPrompt(messageId: string, delta: string, render: RunRender): void {
    const message = render.prompts.get(messageId) ?? null

    if (message === null) {
      return
    }

    // Replaced rather than mutated, so a memoized message component sees the change.
    const updated = createUserMessage(messageId, message.content + delta)

    render.prompts.set(messageId, updated)
    render.turn.messages = render.turn.messages.map((candidate) => (candidate === message ? updated : candidate))
  }

  /**
   * Close the answer on the wire and follow the next one, as a handler sending again stored another message.
   */
  protected startAnotherAnswer(render: RunRender): void {
    render.answer.message.status = 'complete'
    render.answer = new StreamedMessage(this.localId('assistant'))
  }

  /**
   * Add the answer to its turn once it has something to show.
   */
  protected revealAnswer(render: RunRender): void {
    if (render.turn.messages.includes(render.answer.message) || !rendersSomething(render.answer.message)) {
      return
    }

    render.turn.messages = [...render.turn.messages, render.answer.message]
  }

  /**
   * Let go of the resuming status once there is nothing to catch up on, sending whatever was typed meanwhile.
   */
  protected settleResuming(): void {
    if (this.status !== 'resuming') {
      return
    }

    this.status = 'ready'
    this.notify()

    void this.drain()
  }

  /**
   * Determine if a frame of a turn the transport streamed itself is newer than the last one applied.
   */
  protected isFresh(frame: ChatStreamFrame | undefined, render: RunRender): boolean {
    const sequence = frameSequence(frame)

    if (sequence === null) {
      return true
    }

    if (sequence <= render.lastSequence) {
      return false
    }

    render.lastSequence = sequence

    return true
  }

  /**
   * Record the run the turn is answered by, which is what a cancel names.
   */
  protected recordRun(run: ChatRunRef, owner: LocalRun | ObservedRun): void {
    if (!('state' in owner)) {
      owner.run = run

      return
    }

    // A run accepted to be read off the thread's stream already knows its name.
    if (owner.state.kind === 'submitting') {
      this.moveRun(owner, { kind: 'inline', run })
    }
  }

  /**
   * Move this browser's own turn on, handing its run to every cancel that waited to learn it.
   */
  protected moveRun(active: LocalRun, state: Exclude<LocalRunState, { kind: 'submitting' }>): void {
    const previous = active.state

    active.state = state

    if (previous.kind !== 'submitting') {
      return
    }

    for (const resolve of previous.waiting) {
      resolve(state.run)
    }
  }

  /**
   * Settle this browser's own turn where it waits on the thread's stream, with the failure that ended it where one did.
   */
  protected settleActive(failure?: unknown): void {
    if (this.active?.state.kind === 'accepted') {
      this.active.state.settle(failure)
    }
  }

  /**
   * Get the run the turn is answered by, waiting for the send to be accepted where it has not been.
   */
  protected acceptedRun(active: LocalRun): Promise<ChatRunRef | null> {
    const state = active.state

    if (state.kind !== 'submitting') {
      return Promise.resolve(state.run)
    }

    return new Promise((resolve) => {
      state.waiting = [...state.waiting, resolve]
    })
  }

  /**
   * Let go of every cancel waiting on a send that ended without naming its run.
   */
  protected releaseWaiting(active: LocalRun): void {
    const state = active.state

    if (state.kind !== 'submitting') {
      return
    }

    const waiting = state.waiting

    state.waiting = []

    for (const resolve of waiting) {
      resolve(null)
    }
  }

  /**
   * Ask the server to stop the run, sharing one request between concurrent cancels of the same run.
   */
  protected requestCancel(cancel: NonNullable<ChatConnection['cancel']>, run: ChatRunRef): Promise<void> {
    if (this.cancelling?.runId === run.runId) {
      return this.cancelling.request
    }

    const request = cancel({ threadId: run.threadId, runId: run.runId })
      .catch((thrown: unknown) => this.handleCancelFailure(thrown))
      .finally(() => {
        if (this.cancelling?.runId === run.runId) {
          this.cancelling = null
        }
      })

    this.cancelling = { runId: run.runId, request }

    return request
  }

  /**
   * Report a cancel the server never accepted, leaving the read attached to a run that is still answering.
   */
  protected handleCancelFailure(thrown: unknown): void {
    const failure = thrown instanceof Error ? thrown : new Error('The chat run could not be stopped.')

    this.error = failure.message
    this.errorCode = null

    this.notify()
    this.config.onError?.(failure)
  }

  /**
   * Adopt the rows the run persisted, retiring this live turn.
   */
  protected adoptStoredIds(messageId: string | undefined, userMessageId: string | undefined, render: RunRender): void {
    if (typeof messageId === 'string') {
      render.answer.message.id = messageId
      this.transcript.continueStored(render.answer.message)
    }

    if (typeof userMessageId !== 'string') {
      return
    }

    const prompt = render.turn.messages.find((message) => message.role === 'user')

    if (prompt !== undefined) {
      prompt.id = userMessageId
    }
  }

  /**
   * Determine if the event belongs to a call a resumed run is replaying, which another tab knows by the call
   * already being on screen.
   */
  protected resolvesPausedCall(event: AgUiEvent, owner: LocalRun | ObservedRun): boolean {
    switch (event.type) {
      case 'TOOL_CALL_START':
      case 'TOOL_CALL_ARGS':
      case 'TOOL_CALL_END':
      case 'TOOL_CALL_RESULT':
        return (
          owner.render.resolving.has(event.toolCallId) ||
          (owner === this.observed && this.transcript.carriesToolCall(event.toolCallId, owner.render.turn))
        )
      default:
        return false
    }
  }

  /**
   * Record how a replayed call turned out.
   */
  protected recordResolvedCall(event: AgUiEvent): void {
    if (event.type === 'TOOL_CALL_RESULT') {
      this.transcript.recordResolvedCall(event)
    }
  }

  /**
   * Get the transcript, with the turns this browser streamed folded in.
   */
  protected messages(history?: ChatMessage[]): ChatMessage[] {
    const streaming = [this.active?.render.answer.message, this.observed?.render.answer.message]

    return this.transcript.messages(
      history,
      streaming.filter((message) => message !== undefined),
    )
  }

  /**
   * Get the run a retry replays, or null where the last one this browser sent came back answered.
   */
  protected retryableRun(): Run | null {
    if (this.lastRun === null) {
      return null
    }

    const answered = this.lastRun.turn.messages.some((message) => message.role === 'assistant' && !isStopped(message))

    return answered ? null : this.lastRun
  }

  /**
   * Get what the page may do with the thread right now.
   */
  protected abilities(messages: ChatMessage[]): ChatAbilities {
    const capabilities = this.config.source().capabilities ?? {}
    const blocked = this.status === 'resuming'

    return {
      approve: !blocked && capabilities.approvals === true,
      stream: !blocked && capabilities.streaming !== false,
      retry:
        capabilities.retry === true &&
        !this.isBusy() &&
        (this.retryableRun() !== null || unansweredPrompt(messages) !== null),
    }
  }

  /**
   * Send the next queued turn, unless the run before it failed.
   */
  protected async drain(): Promise<void> {
    if (this.status === 'error' || this.pending.length === 0) {
      return
    }

    const [next, ...rest] = this.pending

    this.pending = rest

    await this.run(next.fields, next.turn, next.options)
  }

  /**
   * Get the behavior for a message sent while a run is on the wire.
   */
  protected queueBehavior(): ChatQueue {
    const queue = typeof this.config.queue === 'function' ? this.config.queue() : this.config.queue

    return queue ?? 'queue'
  }

  /**
   * Record a failed run, reporting it to the store only while the run still speaks for it.
   */
  protected handleFailure(thrown: unknown, active: LocalRun, respondingTo?: string): void {
    const message = active.render.answer.message
    const live = this.isLive(active)

    // A canceled fetch is a user action: keep whatever text had arrived.
    if (thrown instanceof DOMException && thrown.name === 'AbortError') {
      const answered = message.content !== ''

      message.status = answered ? 'complete' : 'error'

      if (!answered) {
        message.error = 'Canceled.'
      }

      if (!live) {
        return
      }

      this.status = 'ready'
      this.notify()

      // An interrupt queued its replacement before aborting.
      void this.drain()

      return
    }

    const failure = thrown instanceof Error ? thrown : new Error('The chat request failed.')

    message.status = 'error'
    message.error = failure.message

    if (!live) {
      return
    }

    if (failure instanceof ChatRequestError) {
      this.failRequest(failure, respondingTo)

      return
    }

    this.fail(failure, null)
  }

  /**
   * Report a refused request, with what it said about the fields and the approvals it was answering.
   */
  protected failRequest(failure: ChatRequestError, respondingTo?: string): void {
    if (failure.isApprovalMismatch) {
      this.settleMismatch(failure.approvals, respondingTo)
    }

    this.fail(failure, failure.code, failure.errors)
  }

  /**
   * Put back the approvals the server still has pending, or read how the thread moved past them where it has none.
   */
  protected settleMismatch(approvals: PendingApproval[], respondingTo?: string): void {
    // Decisions the thread has moved past are never sent again.
    this.lastRun = null

    if (approvals.length > 0) {
      this.restoreApprovals(respondingTo, approvals)

      return
    }

    // Once the refused run has unwound, since a read waits on the turn on the wire.
    queueMicrotask(() => void this.sync())
  }

  /**
   * Report a failure to the store, replacing whatever errors it was showing.
   */
  protected fail(failure: Error, code: string | null, validationErrors: Record<string, string[]> = {}): void {
    this.error = failure.message
    this.errorCode = code
    this.validationErrors = validationErrors
    this.status = 'error'

    this.notify()
    this.config.onError?.(failure)
  }

  /**
   * Clear whatever errors the store was showing.
   */
  protected clearErrors(): void {
    this.error = null
    this.errorCode = null
    this.validationErrors = {}
  }

  /**
   * Determine if the run is this browser's turn on the wire.
   */
  protected isLive(run: LocalRun | ObservedRun): boolean {
    return this.active === run
  }

  /**
   * Put the approvals the server still has pending back on the message.
   */
  protected restoreApprovals(messageId: string | undefined, approvals: PendingApproval[]): void {
    if (messageId !== undefined) {
      this.transcript.restoreApprovals(messageId, approvals)
    }
  }

  /**
   * Determine if a turn is being sent or answered.
   */
  protected isStreaming(): boolean {
    return this.status === 'submitted' || this.status === 'streaming'
  }

  /**
   * Determine if the store is streaming or catching up.
   */
  protected isBusy(): boolean {
    return this.isStreaming() || this.status === 'resuming'
  }

  /**
   * Forget everything this browser was holding for the thread.
   */
  protected forgetLocalState(): void {
    this.transcript.forget()
    this.pending = []
    this.lastRun = null
    this.observed = null
    this.lastSettledRunId = null
    this.clearErrors()
    this.status = 'ready'
    this.session.clear()
  }

  /**
   * Mint an id for something this browser is rendering.
   */
  protected localId(prefix: string): string {
    this.sequence += 1

    // The scope repeats on every page load, and the server refuses a message id the thread was already sent.
    return `${this.config.scope ?? 'chat'}-${this.storeId}-${prefix}-${this.sequence}`
  }

  /**
   * Announce a state change to every listener.
   */
  protected notify(): void {
    this.revision += 1
    this.notifying = false

    for (const listener of this.listeners) {
      listener()
    }
  }

  /**
   * Announce a token once per turn of the event loop, since deltas outrun a frame.
   */
  protected notifyStreamed(): void {
    this.revision += 1

    if (this.notifying) {
      return
    }

    this.notifying = true

    queueMicrotask(() => {
      if (!this.notifying) {
        return
      }

      this.notifying = false

      for (const listener of this.listeners) {
        listener()
      }
    })
  }
}

/**
 * Create a new chat store.
 */
export function createChat(config: ChatStoreConfig): ChatStore {
  return new ChatEngine(config)
}

/**
 * Get the prompt no answer ever came back for, which is the turn a retry replays.
 */
function unansweredPrompt(messages: ChatMessage[]): ChatMessage | null {
  for (let index = messages.length - 1; index >= 0; index--) {
    const message = messages[index]

    if (message.role === 'user') {
      return message
    }

    if (!isStopped(message)) {
      return null
    }
  }

  return null
}

/**
 * Determine if the answer stopped short of answering, which a stopped or failed one did.
 */
function isStopped(message: ChatMessage): boolean {
  return message.status === 'cancelled' || message.status === 'error'
}

/**
 * Determine if the message would put anything on screen.
 */
function rendersSomething(message: ChatMessage): boolean {
  return (
    message.parts.length > 0 ||
    message.content !== '' ||
    message.approvals.length > 0 ||
    message.toolCalls.length > 0 ||
    message.error !== null ||
    message.status === 'cancelled'
  )
}
