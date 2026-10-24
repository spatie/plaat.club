import type { ChatSession, ChatStreamFrame } from './types'

/**
 * The thread a browser is following, and how far through its stream it has read.
 */
export class SessionTracker {
  protected session: ChatSession | null

  constructor(
    threadId: string | null,
    offset: number | null | undefined,
    protected onChange: (session: ChatSession | null) => void,
  ) {
    // A payload that names no offset gives nothing to follow from, so the session starts with the first turn sent.
    this.session = threadId === null || typeof offset !== 'number' ? null : { threadId, offset }
  }

  /**
   * Get the thread being followed, or null where this browser is following none.
   */
  public current(): ChatSession | null {
    return this.session
  }

  /**
   * Start following the thread from the offset, unless this browser is reading it already.
   */
  public begin(threadId: string, offset: number): ChatSession {
    // A browser further behind on the same thread keeps its own offset, or the frames in between are skipped.
    if (this.session?.threadId === threadId) {
      return this.session
    }

    this.session = { threadId, offset }
    this.onChange(this.session)

    return this.session
  }

  /**
   * Move the offset to the frame's position, where the frame is past it.
   */
  public advance(frame: ChatStreamFrame | undefined): void {
    const sequence = frameSequence(frame)

    if (sequence !== null) {
      this.catchUp(sequence)
    }
  }

  /**
   * Move the offset to the snapshot's position, even back past where the session was.
   */
  public restartAt(frame: ChatStreamFrame | undefined): void {
    const sequence = frameSequence(frame)

    if (this.session === null || sequence === null) {
      return
    }

    this.moveTo(this.session, sequence)
  }

  /**
   * Move the offset to where a read of the stream caught up to.
   */
  public catchUp(offset: number): void {
    if (this.session === null || offset <= this.session.offset) {
      return
    }

    this.moveTo(this.session, offset)
  }

  /**
   * Forget the thread this browser was following.
   */
  public clear(): void {
    if (this.session === null) {
      return
    }

    this.session = null

    this.onChange(null)
  }

  /**
   * Move the session to the offset, replacing rather than mutating it so a snapshot of the state keeps its own.
   */
  protected moveTo(session: ChatSession, offset: number): void {
    this.session = { threadId: session.threadId, offset }

    this.onChange(this.session)
  }
}

/**
 * Get the frame's position in the stream it was read from, or null where it has none.
 */
export function frameSequence(frame: ChatStreamFrame | undefined): number | null {
  return numericOffset(frame?.id)
}

/**
 * Read an offset that may have arrived as a number or a string.
 */
export function numericOffset(value: unknown): number | null {
  const offset = typeof value === 'string' && value !== '' ? Number(value) : value

  if (typeof offset !== 'number' || !Number.isInteger(offset)) {
    return null
  }

  return offset
}
