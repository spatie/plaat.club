import type { ApprovalDecision } from './types'

/**
 * A turn either says something or answers a pause, never both.
 */
export type TurnFields = { message: string; messageId: string } | { decisions: Record<string, ApprovalDecision> }

/**
 * What one turn is encoded against, beyond the turn itself.
 */
export type TurnContext = {
  threadId: string | null
}

/**
 * Encode one turn as the body the send route reads.
 */
export function runAgentInput(fields: TurnFields, { threadId }: TurnContext): Record<string, unknown> {
  return { ...turnInput(fields), threadId }
}

/**
 * Encode what the turn carries, being a message to send or the decisions that answer a pause.
 */
function turnInput(fields: TurnFields): Record<string, unknown> {
  if ('decisions' in fields) {
    return { messages: [], resume: resumeEntries(fields.decisions) }
  }

  // Only the newest message travels, since the server loads the rest of the transcript itself.
  return { messages: [{ id: fields.messageId, role: 'user', content: fields.message }] }
}

/**
 * Encode the decisions as AG-UI's resume entries.
 */
function resumeEntries(decisions: Record<string, ApprovalDecision>): Record<string, unknown>[] {
  return Object.entries(decisions).map(([interruptId, decision]) => ({
    interruptId,
    status: 'resolved',
    payload: { approved: decision.action === 'approve' },
  }))
}
