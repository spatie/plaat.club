import { isAwaitingContent } from '@laravel/converse'
import { type ReactNode, useImperativeHandle, useMemo } from 'react'
import { useChat } from './hooks/use-chat'
import { useChatComposer } from './hooks/use-chat-composer'
import { useTranscriptScroll } from './hooks/use-transcript-scroll'
import type { ChatComposerBindings, ChatProps, ChatRenderProps } from './types'

/**
 * The page loader for a chat with no older pages.
 */
const loadNothing = (): void => {}

/**
 * Render a chat for a chat payload. A ref receives the same values as the render prop.
 */
export function Chat({
  chat,
  queue,
  history,
  connection,
  headers,
  csrfToken,
  credentials,
  onEvent,
  onFinish,
  onError,
  hasPrevious = false,
  loadPrevious = loadNothing,
  anchorPrompts = true,
  anchorOffset,
  children,
  ref,
}: ChatProps): ReactNode {
  const thread = useChat(() => chat, {
    queue,
    history,
    connection,
    headers,
    csrfToken,
    credentials,
    onEvent,
    onFinish,
    onError,
  })

  const scroll = useTranscriptScroll({ offset: anchorOffset })
  const composer = useChatComposer(thread.store, {
    beginTurn: anchorPrompts
      ? () => {
          void scroll.beginTurn()
        }
      : undefined,
  })

  const composerBindings = useMemo<ChatComposerBindings>(
    () => ({
      draft: composer.draft,
      setDraft: composer.setDraft,
      spacerStyle: scroll.spacerStyle,
      container: scroll.container,
      submit: composer.submit,
      clear: composer.clear,
    }),
    [composer, scroll],
  )

  const renderProps: ChatRenderProps = {
    messages: thread.messages,
    threadId: thread.threadId,
    session: thread.session,
    streaming: thread.streaming,
    status: thread.status,
    error: thread.error,
    errorCode: thread.errorCode,
    validationErrors: thread.validationErrors,
    pendingApprovals: thread.pendingApprovals,
    queued: thread.queued,
    can: thread.can,
    infiniteScrollProps: thread.infiniteScrollProps,
    hasPrevious,
    loadPrevious,
    send: thread.send,
    respond: thread.respond,
    approve: thread.approve,
    reject: thread.reject,
    retry: thread.retry,
    attach: thread.attach,
    sync: thread.sync,
    cancel: thread.cancel,
    clearError: thread.clearError,
    composer: composerBindings,
    isAwaitingContent,
  }

  useImperativeHandle(ref, () => renderProps)

  return children(renderProps)
}
