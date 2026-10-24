<script setup lang="ts">
import type { AgUiEvent, ChatConnection, ChatMessage, ChatQueue, ChatSource } from '@laravel/converse'
import { isAwaitingContent } from '@laravel/converse'
import { computed } from 'vue'
import { useChat } from './composables/useChat'
import { useChatComposer } from './composables/useChatComposer'
import { useTranscriptScroll } from './composables/useTranscriptScroll'
import type { ChatSlotProps, UseChatOptions } from './types'

const props = withDefaults(
  defineProps<{
    /**
     * The payload `Converse::chat()` puts in the page.
     */
    chat: ChatSource
    /**
     * What to do with a message typed while an answer is streaming, read afresh each time one is.
     */
    queue?: ChatQueue
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
  }>(),
  { anchorPrompts: true },
)

const thread = useChat(() => props.chat, {
  queue: () => props.queue,
  history: () => props.history,
  connection: computed(() => props.connection),
  headers: () => props.headers,
  csrfToken: () => props.csrfToken,
  credentials: () => props.credentials,
  onEvent: (event) => props.onEvent?.(event),
  onFinish: (message) => props.onFinish?.(message),
  onError: (error) => props.onError?.(error),
})

const scroll = useTranscriptScroll({ offset: () => props.anchorOffset })

const composer = useChatComposer(thread.store, {
  beginTurn: () => {
    if (props.anchorPrompts) {
      void scroll.beginTurn()
    }
  },
})

// Getters rather than a `reactive()`, which unwraps the refs a page binds to.
const composerBindings = {
  get draft(): string {
    return composer.draft.value
  },
  set draft(value: string) {
    composer.draft.value = value
  },
  get spacerStyle() {
    return scroll.spacerStyle.value
  },
  container: scroll.container,
  submit: composer.submit,
  clear: composer.clear,
}

defineSlots<{ default(props: ChatSlotProps): unknown }>()

defineExpose({ ...thread, composer: composerBindings })
</script>

<template>
  <slot
    :messages="thread.messages.value"
    :thread-id="thread.threadId.value"
    :session="thread.session.value"
    :streaming="thread.streaming.value"
    :status="thread.status.value"
    :error="thread.error.value"
    :error-code="thread.errorCode.value"
    :validation-errors="thread.validationErrors.value"
    :pending-approvals="thread.pendingApprovals.value"
    :queued="thread.queued.value"
    :can="thread.can.value"
    :infinite-scroll-props="thread.infiniteScrollProps.value"
    :has-previous="hasPrevious ?? false"
    :load-previous="loadPrevious ?? (() => {})"
    :send="thread.send"
    :respond="thread.respond"
    :approve="thread.approve"
    :reject="thread.reject"
    :retry="thread.retry"
    :attach="thread.attach"
    :sync="thread.sync"
    :cancel="thread.cancel"
    :clear-error="thread.clearError"
    :composer="composerBindings"
    :is-awaiting-content="isAwaitingContent"
  />
</template>
