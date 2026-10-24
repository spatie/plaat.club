import { createTranscriptScroll } from '@laravel/converse'
import {
  computed,
  nextTick,
  onScopeDispose,
  shallowRef,
  toValue,
  watch,
  type ComputedRef,
  type CSSProperties,
  type MaybeRefOrGetter,
  type Ref,
} from 'vue'

/**
 * The bindings a `useTranscriptScroll` call holds.
 */
export type TranscriptScroll = {
  /**
   * Bind to the scrollable element: `<div ref="container">`.
   */
  container: Ref<HTMLElement | null>
  /**
   * Bind to an empty element rendered last inside the container.
   */
  spacerStyle: ComputedRef<CSSProperties>
  /**
   * Call right after sending.
   */
  beginTurn: () => Promise<void>
}

/**
 * Anchor the newest prompt near the top of the transcript and hold it there.
 */
export function useTranscriptScroll({
  offset,
}: { offset?: MaybeRefOrGetter<number | null | undefined> } = {}): TranscriptScroll {
  const container = shallowRef<HTMLElement | null>(null)
  const spacer = shallowRef(0)

  const scroll = createTranscriptScroll({
    offset: () => toValue(offset),
    onReserve: (height) => {
      spacer.value = height
    },
  })

  // `post`, so the element exists by the time the controller measures it.
  watch(container, (element) => scroll.attach(element), { flush: 'post' })

  onScopeDispose(() => scroll.attach(null))

  return {
    container,
    spacerStyle: computed<CSSProperties>(() => ({
      height: `${spacer.value}px`,
      flex: 'none',
    })),
    beginTurn: () => scroll.beginTurn(() => nextTick()),
  }
}
