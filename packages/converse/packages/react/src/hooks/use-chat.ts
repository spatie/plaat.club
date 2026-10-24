import { createChat, type ChatStore } from '@laravel/converse'
import type {
  ApprovalDecision,
  ChatAbilities,
  ChatMessage,
  ChatQueuedMessage,
  ChatSession,
  ChatSource,
  ChatState,
  ChatStatus,
  InfiniteScrollProps,
  PendingApproval,
} from '@laravel/converse'
import { transportFor } from '@laravel/converse'
import {
  useEffect,
  useId,
  useInsertionEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react'
import type { UseChatOptions, ValueOrGetter } from '../types'

/**
 * The chat payload, or a function returning the current one.
 */
type ChatSourceInput = ValueOrGetter<ChatSource>

/**
 * The payload and options a render was given.
 */
type RenderConfig = {
  source: ChatSource
  options: UseChatOptions
}

/**
 * The thread a `useChat` call holds.
 */
export type UseChatReturn = {
  messages: ChatMessage[]
  threadId: string | null
  /**
   * The thread this browser is following, and how far through its stream it has read.
   */
  session: ChatSession | null
  streaming: boolean
  status: ChatStatus
  error: string | null
  errorCode: string | null
  validationErrors: Record<string, string[]>
  pendingApprovals: PendingApproval[]
  queued: ChatQueuedMessage[]
  can: ChatAbilities
  infiniteScrollProps: InfiniteScrollProps | null
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
 * The layout effect, falling back to a plain effect on the server.
 */
const useIsoLayoutEffect = typeof window === 'undefined' ? useEffect : useLayoutEffect

/**
 * Hold the thread for a chat payload.
 */
export function useChat(source: ChatSourceInput, options: UseChatOptions = {}): UseChatReturn {
  const scope = `chat-${normalizeScope(useId())}`
  const rendered: RenderConfig = { source: valueOf(source), options }
  const committed = useRef(rendered)
  const reading = useRef<RenderConfig | null>(null)

  const current = (): RenderConfig => reading.current ?? committed.current

  const [store] = useState<ChatStore>(() =>
    createChat({
      source: () => current().source,
      connection: transportFor(
        () => current().source,
        () => current().options,
      ),
      history: () => valueOf(current().options.history),
      queue: () => current().options.queue,
      scope,
      onEvent: (event) => current().options.onEvent?.(event),
      onFinish: (message) => current().options.onFinish?.(message),
      onError: (error) => current().options.onError?.(error),
      onSessionChange: (session) => current().options.onSessionChange?.(session),
    }),
  )

  // A render reads its own payload, so a replaced prop is never a render behind, while a send reads the committed one.
  const getSnapshot = (): ChatState => {
    reading.current = rendered

    try {
      return store.getState()
    } finally {
      reading.current = null
    }
  }

  const state = useSyncExternalStore(store.subscribe, getSnapshot, getSnapshot)

  // Insertion effects run before every layout effect, so a child's layout effect already sends with this commit.
  useInsertionEffect(() => {
    committed.current = rendered
  })

  useIsoLayoutEffect(() => {
    store.syncSource()
  }, [store, rendered.source.threadId ?? null])

  useEffect(() => store.mount(), [store])

  return useMemo(
    () => ({
      messages: state.messages,
      threadId: state.threadId,
      session: state.session,
      streaming: state.streaming,
      status: state.status,
      error: state.error,
      errorCode: state.errorCode,
      validationErrors: state.validationErrors,
      pendingApprovals: state.pendingApprovals,
      queued: state.queued,
      can: state.can,
      infiniteScrollProps: state.infiniteScrollProps,
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
    }),
    [state, store],
  )
}

/**
 * Resolve a value that may be given as a getter.
 */
function valueOf<T>(value: ValueOrGetter<T>): T
function valueOf<T>(value: ValueOrGetter<T> | undefined): T | undefined
function valueOf<T>(value: ValueOrGetter<T> | undefined): T | undefined {
  return typeof value === 'function' ? (value as () => T)() : value
}

/**
 * Strip a generated id down to the characters an event scope may contain.
 */
function normalizeScope(scope: string): string {
  return scope.replace(/[^A-Za-z0-9_-]/g, '') || 'react'
}
