import type { AgUiEvent, ChatConnection, ChatMessage, ChatQueue, ChatSession, ChatSource } from '@laravel/converse'
import type { CSSProperties, ReactNode, Ref, RefCallback } from 'react'
import type { UseChatReturn } from './hooks/use-chat'

/**
 * A value, or a function returning the current one.
 */
export type ValueOrGetter<T> = T | (() => T)

/**
 * The options a chat may be created with.
 */
export type UseChatOptions = {
  connection?: ChatConnection
  /**
   * The transcript, when it does not come from the payload.
   */
  history?: ValueOrGetter<ChatMessage[] | undefined>
  /**
   * What to do with a message typed while an answer is streaming, read afresh each time one is.
   */
  queue?: ChatQueue
  /**
   * Extra headers to send with every request.
   */
  headers?: Record<string, string>
  /**
   * Overrides the CSRF token discovery.
   */
  csrfToken?: string
  credentials?: RequestCredentials
  onEvent?: (event: AgUiEvent) => void
  onFinish?: (message: ChatMessage) => void
  onError?: (error: Error) => void
  /**
   * Called with the thread and how far through its stream this browser has read, each time that moves.
   */
  onSessionChange?: (session: ChatSession | null) => void
}

/**
 * The input area's bindings.
 */
export type ChatComposerBindings = {
  draft: string
  setDraft: (value: string) => void
  /**
   * Attach to the scrollable element: `<div ref={composer.container}>`.
   */
  container: RefCallback<HTMLElement>
  /**
   * Render on an empty element placed last inside the container.
   */
  spacerStyle: CSSProperties
  submit: (message?: string) => void
  clear: () => void
}

/**
 * The properties the render function of `<Chat>` receives.
 */
export type ChatRenderProps = Omit<UseChatReturn, 'store'> & {
  hasPrevious: boolean
  loadPrevious: () => void
  composer: ChatComposerBindings
  /**
   * Determine if a message is waiting on its first content.
   */
  isAwaitingContent: (message: ChatMessage) => boolean
}

/**
 * The properties of the `<Chat>` component.
 */
/**
 * What a ref on `<Chat>` receives: the same values as the render prop.
 */
export type ChatRef = ChatRenderProps

export type ChatProps = {
  chat: ChatSource
  /**
   * What to do with a message typed while an answer is streaming, read afresh each time one is.
   */
  queue?: UseChatOptions['queue']
  /**
   * The transcript, when it does not come from the payload.
   */
  history?: ChatMessage[]
  /**
   * Replaces the transport, while the endpoint still comes from the payload.
   */
  connection?: ChatConnection
  headers?: Record<string, string>
  /**
   * Overrides the CSRF token discovery.
   */
  csrfToken?: string
  credentials?: RequestCredentials
  onEvent?: (event: AgUiEvent) => void
  onFinish?: (message: ChatMessage) => void
  onError?: (error: Error) => void
  /**
   * Determine if there is an older page of messages to load.
   */
  hasPrevious?: boolean
  /**
   * Load the previous page of messages.
   */
  loadPrevious?: () => void
  /**
   * Anchor each new prompt near the top of the transcript, on by default.
   */
  anchorPrompts?: boolean
  /**
   * The distance between an anchored prompt and the top of the transcript, in pixels, in place of the container's `scroll-padding-top`.
   */
  anchorOffset?: number
  children: (props: ChatRenderProps) => ReactNode
  /**
   * Receives the same values as the render prop, for code outside it.
   */
  ref?: Ref<ChatRef>
}
