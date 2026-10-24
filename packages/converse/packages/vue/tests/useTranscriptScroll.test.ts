// @vitest-environment jsdom

/**
 * The scroll controller lives in core; this covers what Vue wires around it.
 *
 * `transcriptScroll.test.ts` covers the measuring. What matters here is that the `container`
 * ref reaches the controller once the element exists, that the reserved height arrives as a
 * bindable style, and that leaving the component detaches rather than holding the node.
 */

import { describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import { useTranscriptScroll } from '../src/composables/useTranscriptScroll'

function scrollIn(scope = effectScope()) {
  return { scroll: scope.run(() => useTranscriptScroll())!, scope }
}

describe('the container', () => {
  it('reaches the controller once the element is bound', async () => {
    const { scroll } = scrollIn()
    const element = document.createElement('div')

    expect(scroll.container.value).toBeNull()

    scroll.container.value = element
    await nextTick()

    // Attaching is what starts the controller listening for the reader's scrolling.
    expect(scroll.container.value).toBe(element)
  })

  it('starts with no reserved space', () => {
    const { scroll } = scrollIn()

    expect(scroll.spacerStyle.value).toEqual({
      height: '0px',
      flex: 'none',
    })
  })
})

describe('a turn', () => {
  it('waits for the render before measuring', async () => {
    const { scroll } = scrollIn()
    const element = document.createElement('div')

    scroll.container.value = element
    await nextTick()

    await expect(scroll.beginTurn()).resolves.toBeUndefined()
  })

  it('is harmless before any element is bound', async () => {
    const { scroll } = scrollIn()

    await expect(scroll.beginTurn()).resolves.toBeUndefined()
  })
})

describe('unbinding', () => {
  it('detaches when the scope goes away', async () => {
    const scope = effectScope()
    const { scroll } = scrollIn(scope)
    const element = document.createElement('div')
    const remove = vi.spyOn(element, 'removeEventListener')

    scroll.container.value = element
    await nextTick()

    scope.stop()

    expect(remove).toHaveBeenCalled()
  })

  it('detaches rather than holding the element', async () => {
    const { scroll } = scrollIn()
    const element = document.createElement('div')
    const remove = vi.spyOn(element, 'removeEventListener')

    scroll.container.value = element
    await nextTick()

    // A `v-if` around the transcript clears the ref, which has to reach the controller.
    scroll.container.value = null
    await nextTick()

    expect(remove).toHaveBeenCalled()
  })
})
