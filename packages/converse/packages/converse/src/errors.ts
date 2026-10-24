import type { PendingApproval } from './types'

/**
 * A failed response, as a `StreamResponseError` reports it.
 */
export type StreamResponseFailure = { status: number; body: string }

/**
 * Thrown for a response that never became a stream.
 */
export class ChatRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: Record<string, string[]> = {},
    public readonly approvals: PendingApproval[] = [],
    // The raw body too: a busy `409` names the run holding the thread in fields this error has no place for.
    public readonly body: string = '',
    public readonly code: string | null = null,
  ) {
    super(message)
    this.name = 'ChatRequestError'
  }

  /**
   * Determine if the response refused a resume the thread had moved past.
   */
  get isApprovalMismatch(): boolean {
    return this.code === 'approval_mismatch'
  }
}

/**
 * Thrown for a turn the server refused because another run holds the thread, naming that run and the stream
 * position its events begin after.
 */
export class ChatThreadBusyError extends Error {
  constructor(
    public readonly threadId: string,
    public readonly runId: string,
    public readonly offset: number,
  ) {
    super('The chat thread is already answering another turn.')
    this.name = 'ChatThreadBusyError'
  }
}

/**
 * Thrown when a read has been reconnecting with nothing to show for it.
 */
export class ChatStreamStalledError extends Error {
  constructor(
    public readonly attempts: number,
    public readonly reason: unknown = null,
  ) {
    super('The chat stream stopped responding.')
    this.name = 'ChatStreamStalledError'
  }
}

/**
 * Thrown for a stream that opened and then stopped sending bytes.
 */
export class SseStreamIdleError extends Error {
  constructor(public readonly timeout: number) {
    super(`The chat stream sent nothing for ${timeout}ms.`)
    this.name = 'SseStreamIdleError'
  }
}

/**
 * Build the error a failed request is reported to the store as.
 */
export function toRequestError(error: StreamResponseFailure): ChatRequestError {
  const fallback = `The chat endpoint failed with status ${error.status}.`

  try {
    const body = JSON.parse(error.body) as {
      message?: string
      errors?: Record<string, string[]>
      approvals?: PendingApproval[]
      code?: string
    }

    return new ChatRequestError(
      body.message || fallback,
      error.status,
      body.errors ?? {},
      body.approvals ?? [],
      error.body,
      typeof body.code === 'string' ? body.code : null,
    )
  } catch {
    return new ChatRequestError(fallback, error.status, {}, [], error.body)
  }
}
