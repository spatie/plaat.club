/**
 * The scroll controller lives in core; this covers what React wires around it.
 *
 * `transcriptScroll.test.ts` covers the measuring. What matters here is that the ref callback
 * reaches the controller, that the reserved height arrives as a style object, and that React 19's
 * ref cleanup detaches instead of leaving the controller holding a node that has left the page.
 */

import { act, cleanup, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useTranscriptScroll } from '../src/hooks/use-transcript-scroll'

let latest: ReturnType<typeof useTranscriptScroll>
let setMounted: (mounted: boolean) => void

function Harness({ element }: { element?: HTMLElement }) {
  const scroll = useTranscriptScroll()
  const [mounted, setState] = useState(true)

  latest = scroll
  setMounted = setState

  return (
    <div data-testid="height" style={scroll.spacerStyle}>
      {mounted ? (
        <div data-testid="container" ref={element ? () => scroll.container(element) : scroll.container} />
      ) : null}
    </div>
  )
}

afterEach(cleanup)

describe('the container', () => {
  it('starts with no reserved space', () => {
    render(<Harness />)

    expect(screen.getByTestId('height').style.height).toBe('0px')
  })

  it('detaches when the element leaves the page', () => {
    const element = document.createElement('div')
    const remove = vi.spyOn(element, 'removeEventListener')

    render(<Harness element={element} />)

    // React 19 calls the cleanup a ref callback returns, which is what detaches.
    act(() => setMounted(false))

    expect(remove).toHaveBeenCalled()
  })

  it('detaches the way React 18 does, by calling the ref with null and ignoring the cleanup', () => {
    const element = document.createElement('div')
    const remove = vi.spyOn(element, 'removeEventListener')

    render(<Harness />)

    act(() => {
      latest.container(element)
      latest.container(null)
    })

    expect(remove).toHaveBeenCalled()
  })
})

describe('a turn', () => {
  it('is harmless before any element is bound', async () => {
    render(<Harness />)

    await expect(latest.beginTurn()).resolves.toBeUndefined()
  })

  it('waits for the render before measuring', async () => {
    const element = document.createElement('div')

    render(<Harness element={element} />)

    await expect(latest.beginTurn()).resolves.toBeUndefined()
  })
})
