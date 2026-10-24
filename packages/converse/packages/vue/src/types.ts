import type { AgUiEvent, ChatConnection, ChatMessage, ChatQueue, ChatSession } from '@laravel/converse'
import type { ComputedRef, CSSProperties, MaybeRef, MaybeRefOrGetter, Ref } from 'vue'
import type { UseChatReturn } from './composables/useChat'

/**
 * The options a chat may be created with.
 */
export type UseChatOptions = {
  /**
   * Replaces the transport, read afresh for every request like the three options that build the default one.
   */
  connection?: MaybeRef<ChatConnection | undefined> | ComputedRef<ChatConnection | undefined>
  /**
   * The transcript, when it does not come from the payload.
   */
  history?: MaybeRefOrGetter<ChatMessage[] | undefined>
  /**
   * What to do with a message typed while an answer is streaming, read afresh each time one is.
   */
  queue?: MaybeRefOrGetter<ChatQueue | undefined>
  /**
   * Extra headers to send with every request.
   */
  headers?: MaybeRefOrGetter<Record<string, string> | undefined>
  /**
   * Overrides the CSRF token discovery.
   */
  csrfToken?: MaybeRefOrGetter<string | undefined>
  credentials?: MaybeRefOrGetter<RequestCredentials | undefined>
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
  /**
   * Two-way bindable: `v-model="composer.draft"`.
   */
  draft: string
  /**
   * Bind to the scrollable element: `:ref="composer.container"`.
   */
  container: Ref<HTMLElement | null>
  /**
   * Bind to an empty element rendered last inside the container.
   */
  spacerStyle: CSSProperties
  submit: (message?: string) => void
  clear: () => void
}

/**
 * The value a computed holds, since a slot receives values rather than refs.
 */
type Unwrap<T> = T extends ComputedRef<infer Value> ? Value : T

/**
 * The properties the default slot of `<Chat>` receives.
 */
export type ChatSlotProps = {
  [Key in keyof Omit<UseChatReturn, 'store'>]: Unwrap<UseChatReturn[Key]>
} & {
  hasPrevious: boolean
  loadPrevious: () => void
  composer: ChatComposerBindings
  /**
   * Determine if a message is waiting on its first content.
   */
  isAwaitingContent: (message: ChatMessage) => boolean
}
