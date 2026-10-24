import { describe, expect, it } from 'vitest'
import { createChat } from '../src/chat'
import type { ChatConnectionRequest, ChatSource } from '../src/types'

function capture(): {
  bodies: Record<string, unknown>[]
  connection: (r: ChatConnectionRequest) => Promise<void>
} {
  const bodies: Record<string, unknown>[] = []

  return {
    bodies,
    connection: async (request) => {
      bodies.push(request.body)

      request.onEvent({
        type: 'RUN_FINISHED',
        threadId: 'conv-1',
        runId: 'run-1',
      })
    },
  }
}

function chat(source: ChatSource = { endpoint: '/chat', threadId: 'conv-1' }) {
  const { bodies, connection } = capture()

  return { bodies, store: createChat({ source: () => source, connection }) }
}

describe('the request body', () => {
  it('carries only the newest message and the thread for a new turn', async () => {
    const { bodies, store } = chat()

    await store.send('Hello')

    expect(bodies[0]).toEqual({
      messages: [{ id: expect.any(String), role: 'user', content: 'Hello' }],
      threadId: 'conv-1',
    })
  })

  // Converse mints the thread id for a first turn rather than trusting the browser's.
  it('sends a null thread id for a thread the server has not named yet', async () => {
    const { bodies, store } = chat({ endpoint: '/chat' })

    await store.send('Hello')

    expect(bodies[0].threadId).toBeNull()
  })

  it('carries the decisions as resume entries and no message for a resume', async () => {
    const { bodies, store } = chat()

    await store.respond({ 'call-1': { action: 'approve' } })

    expect(bodies[0]).toEqual({
      messages: [],
      resume: [{ interruptId: 'call-1', status: 'resolved', payload: { approved: true } }],
      threadId: 'conv-1',
    })
  })

  it('sends a rejection as a plain refusal', async () => {
    const { bodies, store } = chat()

    await store.reject('call-1')

    expect(bodies[0].resume).toEqual([{ interruptId: 'call-1', status: 'resolved', payload: { approved: false } }])
  })
})
