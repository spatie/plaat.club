import { createChatComposer, type ChatComposer, type ChatStore } from '@laravel/converse'
import { customRef, onScopeDispose, type Ref } from 'vue'

/**
 * The options a composer may be created with.
 */
export type UseChatComposerOptions = {
  beginTurn?: () => void
}

/**
 * The draft a `useChatComposer` call holds.
 */
export type UseChatComposer = Pick<ChatComposer, 'submit' | 'clear'> & {
  /**
   * Two-way bindable: `v-model="draft"`.
   */
  draft: Ref<string>
}

/**
 * Hold the input area's draft for a chat store.
 */
export function useChatComposer(chat: ChatStore, { beginTurn }: UseChatComposerOptions = {}): UseChatComposer {
  const composer = createChatComposer({ chat, beginTurn })

  // A `customRef`, since the draft lives in the core composer and a mirrored `ref` would drift from it.
  const draft = customRef<string>((track, trigger) => {
    const stop = composer.subscribe(trigger)

    onScopeDispose(stop)

    return {
      get: () => {
        track()

        return composer.getDraft()
      },
      set: (value) => composer.setDraft(value),
    }
  })

  return {
    draft,
    submit: composer.submit,
    clear: composer.clear,
  }
}
