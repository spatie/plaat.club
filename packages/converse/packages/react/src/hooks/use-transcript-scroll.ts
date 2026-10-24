import { createTranscriptScroll } from '@laravel/converse'
import { useCallback, useInsertionEffect, useMemo, useRef, useState, type CSSProperties, type RefCallback } from 'react'

/**
 * The bindings a `useTranscriptScroll` call holds.
 */
export type TranscriptScroll = {
  /**
   * Attach to the scrollable element: `<div ref={container}>`.
   */
  container: RefCallback<HTMLElement>
  /**
   * Render on an empty element placed last inside the container.
   */
  spacerStyle: CSSProperties
  /**
   * Call right after sending.
   */
  beginTurn: () => Promise<void>
}

/**
 * Anchor the newest prompt near the top of the transcript and hold it there.
 */
export function useTranscriptScroll({ offset }: { offset?: number | null } = {}): TranscriptScroll {
  const [spacer, setSpacer] = useState(0)
  const latest = useRef(offset)

  useInsertionEffect(() => {
    latest.current = offset
  })

  const [scroll] = useState(() =>
    createTranscriptScroll({
      offset: () => latest.current,
      onReserve: setSpacer,
    }),
  )

  const container = useCallback<RefCallback<HTMLElement>>(
    (element) => {
      scroll.attach(element)

      return () => scroll.attach(null)
    },
    [scroll],
  )

  return useMemo(
    () => ({
      container,
      spacerStyle: {
        height: `${spacer}px`,
        flex: 'none',
      },
      beginTurn: () => scroll.beginTurn(afterRender),
    }),
    [container, scroll, spacer],
  )
}

/**
 * Resolve once the browser has painted the frame this turn caused.
 */
function afterRender(): Promise<void> {
  return new Promise((resolve) => {
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(() => resolve())

      return
    }

    setTimeout(resolve, 0)
  })
}
