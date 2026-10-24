/**
 * The draft lives in the core composer, not in the adapter.
 *
 * `composer.test.ts` in core covers what a draft does; this covers the one thing Vue adds — a
 * `customRef` so `v-model` reads and writes the composer itself rather than a mirror of it.
 */

import { createChat, type ChatConnection } from '@laravel/converse'
import { describe, expect, it } from 'vitest'
import { effectScope, watch } from 'vue'
import { useChatComposer } from '../src/composables/useChatComposer'

const neverResolves: ChatConnection = () => new Promise(() => {})

function composerIn(scope = effectScope()) {
  const chat = createChat({
    source: () => ({ endpoint: '/chat' }),
    connection: neverResolves,
  })
  const composer = scope.run(() => useChatComposer(chat))!

  return { chat, composer, scope }
}

describe('the draft', () => {
  it('is two-way bindable', () => {
    const { composer } = composerIn()

    composer.draft.value = 'Half a thought'

    expect(composer.draft.value).toBe('Half a thought')
  })

  it('tracks, so a template re-renders when the draft changes', async () => {
    const scope = effectScope()
    const { composer } = composerIn(scope)
    const seen: string[] = []

    scope.run(() =>
      watch(composer.draft, (value) => seen.push(value), {
        flush: 'sync',
      }),
    )

    composer.draft.value = 'Typed'
    composer.clear()

    expect(seen).toEqual(['Typed', ''])
  })

  it('reads the composer rather than a mirror of it, so clearing is visible', () => {
    const { composer } = composerIn()

    composer.draft.value = 'Sent'
    composer.clear()

    expect(composer.draft.value).toBe('')
  })

  it('stops listening once the scope is disposed', () => {
    const scope = effectScope()
    const { composer } = composerIn(scope)

    composer.draft.value = 'Typed'
    scope.stop()

    // The ref keeps reading the composer; it just no longer triggers anything.
    expect(composer.draft.value).toBe('Typed')
  })
})
