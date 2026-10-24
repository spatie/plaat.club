import { createChat, type ChatState, type ChatStore } from '@laravel/converse'
import type {
  ApprovalDecision,
  ChatAbilities,
  ChatMessage,
  ChatQueuedMessage,
  ChatSession,
  ChatSource,
  ChatStatus,
  InfiniteScrollProps,
  PendingApproval,
} from '@laravel/converse'
import { transportFor } from '@laravel/converse'
import {
  computed,
  getCurrentInstance,
  onScopeDispose,
  shallowRef,
  toValue,
  unref,
  useId,
  watch,
  type ComputedRef,
  type MaybeRefOrGetter,
} from 'vue'
import type { UseChatOptions } from '../types'

/**
 * The thread a `useChat` call holds.
 */
export type UseChatReturn = {
  messages: ComputedRef<ChatMessage[]>
  threadId: ComputedRef<string | null>
  /**
   * The thread this browser is following, and how far through its stream it has read.
   */
  session: ComputedRef<ChatSession | null>
  streaming: ComputedRef<boolean>
  status: ComputedRef<ChatStatus>
  error: ComputedRef<string | null>
  errorCode: ComputedRef<string | null>
  validationErrors: ComputedRef<Record<string, string[]>>
  pendingApprovals: ComputedRef<PendingApproval[]>
  queued: ComputedRef<ChatQueuedMessage[]>
  can: ComputedRef<ChatAbilities>
  infiniteScrollProps: ComputedRef<InfiniteScrollProps | null>
  send: (message: string) => Promise<boolean>
  respond: (decisions: Record<string, ApprovalDecision>) => Promise<void>
  approve: (toolCallId: string) => Promise<void>
  reject: (toolCallId: string) => Promise<void>
  retry: () => Promise<void>
  attach: () => Promise<void>
  sync: () => Promise<void>
  cancel: () => Promise<void>
  clearError: () => void
  /**
   * The framework-free store underneath, for anything this does not surface.
   */
  store: ChatStore
}

/**
 * Hold the thread for a chat payload.
 */
export function useChat(source: MaybeRefOrGetter<ChatSource>, options: UseChatOptions = {}): UseChatReturn {
  // `useId` is only callable inside a component, and this need not be one.
  const scope = getCurrentInstance() === null ? 'chat' : useId()

  const payload = (): ChatSource => toValue(source)

  // `unref` rather than `toValue` for the connection, since a transport is itself a function.
  const connection = transportFor(payload, () => ({
    connection: unref(options.connection),
    headers: toValue(options.headers),
    credentials: toValue(options.credentials),
    csrfToken: toValue(options.csrfToken),
  }))

  const store: ChatStore = createChat({
    source: payload,
    connection,
    history: () => toValue(options.history),
    queue: () => toValue(options.queue),
    scope,
    onEvent: options.onEvent,
    onFinish: options.onFinish,
    onError: options.onError,
    onSessionChange: options.onSessionChange,
  })

  // A counter, since the store's state is not reactive and only its notifications say the snapshot moved.
  const revision = shallowRef(0)

  const unsubscribe = store.subscribe(() => {
    revision.value += 1
  })

  // `getState()` calls the source getter from inside the computed, so Vue tracks the payload it reads.
  const state = computed<ChatState>(() => {
    void revision.value

    return store.getState()
  })

  // One computed per field, so a template reading only `status` does not re-render for every token.
  const select = <Key extends keyof ChatState>(key: Key): ComputedRef<ChatState[Key]> =>
    computed(() => state.value[key])

  watch(() => payload().threadId ?? null, store.syncSource)

  const unmount = store.mount()

  onScopeDispose(() => {
    unsubscribe()
    unmount()
  })

  return {
    messages: select('messages'),
    threadId: select('threadId'),
    session: select('session'),
    streaming: select('streaming'),
    status: select('status'),
    error: select('error'),
    errorCode: select('errorCode'),
    validationErrors: select('validationErrors'),
    pendingApprovals: select('pendingApprovals'),
    queued: select('queued'),
    can: select('can'),
    infiniteScrollProps: select('infiniteScrollProps'),
    store,
    send: store.send,
    respond: store.respond,
    approve: store.approve,
    reject: store.reject,
    retry: store.retry,
    attach: store.attach,
    sync: store.sync,
    cancel: store.cancel,
    clearError: store.clearError,
  }
}
