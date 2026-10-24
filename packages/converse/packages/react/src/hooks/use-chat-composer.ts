import { createChatComposer, type ChatComposer, type ChatStore } from '@laravel/converse'
import { useInsertionEffect, useMemo, useRef, useSyncExternalStore } from 'react'

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
  draft: string
  setDraft: (value: string) => void
  composer: ChatComposer
}

/**
 * Hold the input area's draft for a chat store.
 */
export function useChatComposer(chat: ChatStore, { beginTurn }: UseChatComposerOptions = {}): UseChatComposer {
  const latest = useRef({ beginTurn })

  useInsertionEffect(() => {
    latest.current = { beginTurn }
  })

  const composer = useMemo(
    () =>
      createChatComposer({
        chat,
        beginTurn: () => latest.current.beginTurn?.(),
      }),
    [chat],
  )

  const draft = useSyncExternalStore(composer.subscribe, composer.getDraft, composer.getDraft)

  return useMemo(
    () => ({
      draft,
      setDraft: composer.setDraft,
      submit: composer.submit,
      clear: composer.clear,
      composer,
    }),
    [composer, draft],
  )
}
