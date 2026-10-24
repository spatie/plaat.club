// @vitest-environment jsdom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createTranscriptScroll, promptAttribute } from '../src/transcriptScroll'

const afterRender = (): Promise<void> => Promise.resolve()

type ManualRaf = {
  advance: (milliseconds: number) => void
  finish: () => void
}

type ScrollBox = {
  element: HTMLElement
  grow: (height: number) => void
  prompt: (top: number) => HTMLElement
  reserve: (height: number) => void
}

let raf: ManualRaf

beforeEach(() => {
  raf = installManualRaf()
})

afterEach(() => {
  document.body.replaceChildren()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('turn anchoring', () => {
  it('reserves spacer on beginTurn and shrinks it as content grows', async () => {
    const box = createScrollBox()
    const reserves: number[] = []
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        box.reserve(height)
      },
    })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    // The leading `0` is `attach` dropping whatever the last element was sized
    // for: a fresh element starts with no reservation of its own.
    expect(reserves).toEqual([0, 400, 156])
    expect(box.element.scrollTop).toBe(276)

    box.grow(80)
    await flushMutations()

    expect(reserves).toEqual([0, 400, 156, 76])
    expect(box.element.scrollTop).toBe(276)
  })

  it.each([
    [undefined, 276],
    [null, 276],
    [40, 260],
    [0, 300],
    [-40, 300],
  ])('leaves an offset of %s above the prompt', async (offset, scrollTop) => {
    const box = createScrollBox()
    const controller = createTranscriptScroll({
      offset: offset as number | undefined,
      onReserve: (height) => box.reserve(height),
    })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    expect(box.element.scrollTop).toBe(scrollTop)
  })

  it('reads an offset getter each time it anchors a prompt', async () => {
    const box = createScrollBox()
    let offset = 40
    const controller = createTranscriptScroll({ offset: () => offset, onReserve: (height) => box.reserve(height) })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    expect(box.element.scrollTop).toBe(260)

    offset = 60
    await controller.beginTurn(afterRender)
    raf.finish()

    expect(box.element.scrollTop).toBe(240)
  })

  it.each([
    ['40px', undefined, 260],
    ['10%', undefined, 260],
    ['calc(12px + 5%)', undefined, 268],
    ['auto', undefined, 276],
    ['40px', 0, 300],
  ])('leaves a scroll-padding-top of %s above the prompt, unless given %s', async (padding, offset, scrollTop) => {
    vi.stubGlobal('getComputedStyle', () => ({ scrollPaddingTop: padding }))

    // jsdom lays nothing out, so the probe reports what a browser resolves against the 400px box.
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      return { height: { '10%': 40, 'calc(5% + 12px)': 32 }[this.style.height] ?? 0 } as DOMRect
    })

    const box = createScrollBox()
    const controller = createTranscriptScroll({ offset, onReserve: (height) => box.reserve(height) })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    expect(box.element.scrollTop).toBe(scrollTop)
    expect(document.body.children).toHaveLength(1)
  })

  it('reserves nothing while the whole thread fits the box', async () => {
    const box = createScrollBox({ contentHeight: 300 })
    const reserves: number[] = []
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        box.reserve(height)
      },
    })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    expect(reserves).toEqual([0, 400, 0])
    expect(box.element.scrollHeight).toBe(300)
    expect(box.element.scrollTop).toBe(0)

    // Outgrowing the box is the first moment the prompt needs holding up.
    box.grow(150)
    await flushMutations()

    expect(reserves).toEqual([0, 400, 0, 226])
    expect(box.element.scrollTop).toBe(276)
  })

  it('re-asserts the held position when content mutates', async () => {
    const box = createScrollBox()
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        box.reserve(height)
      },
    })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    box.element.scrollTop = 180
    box.grow(80)
    await flushMutations()

    expect(box.element.scrollTop).toBe(276)
  })

  it('does nothing when beginTurn finds no prompt', async () => {
    const box = createScrollBox()
    const reserves: number[] = []
    const spiedAfterRender = vi.fn(() => Promise.resolve())
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        box.reserve(height)
      },
    })

    controller.attach(box.element)
    const scrollTop = box.element.scrollTop
    await controller.beginTurn(spiedAfterRender)
    raf.finish()

    expect(spiedAfterRender).toHaveBeenCalledTimes(1)
    expect(reserves).toEqual([0])
    expect(box.element.scrollTop).toBe(scrollTop)
  })
})

describe('reader gestures', () => {
  it('releases the hold after a wheel gesture', async () => {
    const { box } = await heldTurn()

    box.element.dispatchEvent(new Event('wheel'))
    box.element.scrollTop = 180
    box.grow(80)
    await flushMutations()

    expect(box.element.scrollTop).toBe(180)
  })

  it('releases the hold after a keydown gesture', async () => {
    const { box } = await heldTurn()

    box.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }))
    box.element.scrollTop = 180
    box.grow(80)
    await flushMutations()

    expect(box.element.scrollTop).toBe(180)
  })
})

describe('opening a transcript', () => {
  it('opens on the newest message', () => {
    const box = createScrollBox()
    const controller = createTranscriptScroll({
      onReserve: (height) => box.reserve(height),
    })

    controller.attach(box.element)

    expect(box.element.scrollTop).toBe(120)
  })

  /*
   * Markdown, fonts and images land after the element does, so the bottom the
   * transcript opened on was not the real one. Following it as the content
   * settles is what stops a refresh landing mid-thread.
   */
  it('keeps following the bottom while late content lands', async () => {
    const box = createScrollBox()
    const controller = createTranscriptScroll({
      onReserve: (height) => box.reserve(height),
    })

    controller.attach(box.element)
    box.grow(300)
    await flushMutations()

    expect(box.element.scrollTop).toBe(420)
  })

  it('stops following once the reader scrolls away', async () => {
    const box = createScrollBox()
    const controller = createTranscriptScroll({
      onReserve: (height) => box.reserve(height),
    })

    controller.attach(box.element)
    box.element.dispatchEvent(new Event('wheel'))
    box.element.scrollTop = 0
    box.grow(300)
    await flushMutations()

    expect(box.element.scrollTop).toBe(0)
  })
})

describe('attachment', () => {
  it('removes listeners and disconnects the observer when detached', async () => {
    const box = createScrollBox()
    const reserves: number[] = []
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        box.reserve(height)
      },
    })

    box.prompt(300)
    controller.attach(box.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    controller.attach(null)
    const detachedReserveCount = reserves.length
    box.grow(80)
    await flushMutations()

    expect(reserves).toHaveLength(detachedReserveCount)

    box.element.dispatchEvent(new Event('wheel'))
    controller.attach(box.element)
    box.element.scrollTop = 100
    box.grow(20)
    await flushMutations()

    // Re-attaching is a fresh start, not a resumption: the element follows its
    // own bottom (620 - 400) rather than the hold the finished turn left at 276.
    expect(box.element.scrollTop).toBe(220)
  })

  it('detaches the first element when a second element is attached', async () => {
    const first = createScrollBox()
    const second = createScrollBox()
    const reserves: number[] = []
    let activeBox = first
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        activeBox.reserve(height)
      },
    })

    first.prompt(300)
    second.prompt(300)
    controller.attach(first.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    activeBox = second
    controller.attach(second.element)
    const detachedReserveCount = reserves.length
    first.grow(80)
    await flushMutations()

    expect(reserves).toHaveLength(detachedReserveCount)

    first.element.dispatchEvent(new Event('wheel'))
    second.element.scrollTop = 100
    second.grow(80)
    await flushMutations()

    // The gesture went to the element nobody is watching, and the second
    // element follows its own bottom (600 - 400) rather than the first
    // element's hold.
    expect(second.element.scrollTop).toBe(200)
  })

  /*
   * The two boxes above are built identically, so a position carried over from
   * the first is indistinguishable from one measured in the second. This one is
   * built taller on purpose: every number asserted below can only have come
   * from the element the controller is actually watching.
   */
  it('opens a second element on its own bottom rather than the first element’s hold', async () => {
    const first = createScrollBox()
    const second = createScrollBox({ contentHeight: 900 })
    const reserves: number[] = []
    let activeBox = first
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        activeBox.reserve(height)
      },
    })

    first.prompt(300)
    controller.attach(first.element)
    await controller.beginTurn(afterRender)
    raf.finish()

    // A finished turn: held at 276, with 156px reserved, both in the first
    // element's coordinates.
    expect(first.element.scrollTop).toBe(276)
    expect(reserves.at(-1)).toBe(156)

    activeBox = second
    controller.attach(second.element)

    // The reservation goes with the element it was measured for.
    expect(reserves.at(-1)).toBe(0)
    expect(second.element.scrollTop).toBe(500)

    second.grow(100)
    await flushMutations()

    // And it keeps following, which is what the hold would have killed.
    expect(second.element.scrollTop).toBe(600)
  })

  /*
   * The swap lands inside the turn rather than after it. `beginTurn` waits twice for the adapter to render,
   * and an element swap is free to happen across either wait — a `v-if` flipping, a ref callback firing —
   * which is the same re-attach the two tests above cover, just arriving a moment earlier. The turn was
   * measured inside the element the controller has stopped watching, so it has nothing left to say.
   */
  it('drops a turn whose element is swapped out before the anchor is written', async () => {
    const first = createScrollBox()
    const second = createScrollBox({ contentHeight: 900 })
    const reserves: number[] = []
    let activeBox = first
    let renders = 0
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        activeBox.reserve(height)
      },
    })

    const swapOnFirstRender = async (): Promise<void> => {
      await Promise.resolve()

      if (renders++ > 0) {
        return
      }

      activeBox = second
      controller.attach(second.element)
    }

    first.prompt(300)
    controller.attach(first.element)
    await controller.beginTurn(swapOnFirstRender)
    raf.finish()

    // The second element opens on its own bottom (900 - 400).
    expect(second.element.scrollTop).toBe(500)

    second.grow(100)
    await flushMutations()

    // Following its own bottom, not held at an offset measured in a box it never shared.
    expect(second.element.scrollTop).toBe(600)

    // Only the two attaches reserved: the abandoned turn sized nothing for the element it did not watch.
    expect(reserves).toEqual([0, 0])
  })

  it('drops a turn whose element is swapped out before the glide starts', async () => {
    const first = createScrollBox()
    const second = createScrollBox({ contentHeight: 900 })
    const reserves: number[] = []
    let activeBox = first
    let renders = 0
    const controller = createTranscriptScroll({
      onReserve: (height) => {
        reserves.push(height)
        activeBox.reserve(height)
      },
    })

    const swapOnSecondRender = async (): Promise<void> => {
      await Promise.resolve()

      if (renders++ !== 1) {
        return
      }

      activeBox = second
      controller.attach(second.element)
    }

    first.prompt(300)
    controller.attach(first.element)

    expect(first.element.scrollTop).toBe(120)

    await controller.beginTurn(swapOnSecondRender)
    raf.finish()

    // The anchor was written and then thrown away by the attach, so the last word on the reservation is the
    // attach's own zero — not a height computed from the first element's content.
    expect(reserves).toEqual([0, 400, 0])

    // And the glide never ran: the element it was aimed at is one nobody is watching.
    expect(first.element.scrollTop).toBe(120)
    expect(second.element.scrollTop).toBe(500)
  })
})

async function heldTurn(): Promise<{ box: ScrollBox }> {
  const box = createScrollBox()
  const controller = createTranscriptScroll({
    onReserve: (height) => {
      box.reserve(height)
    },
  })

  box.prompt(300)
  controller.attach(box.element)
  await controller.beginTurn(afterRender)
  raf.finish()

  return { box }
}

function createScrollBox({
  clientHeight = 400,
  contentHeight: initialContentHeight = 520,
}: {
  clientHeight?: number
  contentHeight?: number
} = {}): ScrollBox {
  const element = document.createElement('div')
  const text = document.createTextNode('content')
  const textHost = document.createElement('p')
  let contentHeight = initialContentHeight
  let spacer = 0
  let scrollTop = 0

  textHost.append(text)
  element.append(textHost)
  document.body.append(element)

  const maxScroll = (): number => Math.max(0, contentHeight + spacer - clientHeight)

  Object.defineProperty(element, 'clientHeight', {
    configurable: true,
    get: () => clientHeight,
  })

  Object.defineProperty(element, 'scrollHeight', {
    configurable: true,
    get: () => contentHeight + spacer,
  })

  Object.defineProperty(element, 'scrollTop', {
    configurable: true,
    get: () => scrollTop,
    set: (top: number) => {
      scrollTop = clamp(top, 0, maxScroll())
    },
  })

  Object.defineProperty(element, 'scrollTo', {
    configurable: true,
    value: (options: ScrollToOptions) => {
      element.scrollTop = options.top ?? 0
    },
  })

  element.getBoundingClientRect = () => rect(0, clientHeight)

  return {
    element,
    grow: (height) => {
      contentHeight += height
      scrollTop = clamp(scrollTop, 0, maxScroll())
      text.data = `${contentHeight}`
    },
    prompt: (top) => {
      const prompt = document.createElement('div')

      prompt.setAttribute(promptAttribute, '')
      prompt.getBoundingClientRect = () => rect(top - element.scrollTop, 20)
      element.append(prompt)

      return prompt
    },
    reserve: (height) => {
      spacer = height
      scrollTop = clamp(scrollTop, 0, maxScroll())
    },
  }
}

function installManualRaf(): ManualRaf {
  const frames = new Map<number, FrameRequestCallback>()
  let nextFrame = 1
  let now = 0

  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback): number => {
    const frame = nextFrame++

    frames.set(frame, callback)

    return frame
  })

  vi.stubGlobal('cancelAnimationFrame', (frame: number): void => {
    frames.delete(frame)
  })

  vi.spyOn(performance, 'now').mockImplementation(() => now)

  const advance = (milliseconds: number): void => {
    now += milliseconds

    const pendingFrames = [...frames.values()]

    frames.clear()

    for (const frame of pendingFrames) {
      frame(now)
    }
  }

  return {
    advance,
    finish: () => {
      while (frames.size > 0) {
        advance(320)
      }
    },
  }
}

async function flushMutations(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

function rect(top: number, height: number): DOMRect {
  return {
    bottom: top + height,
    height,
    left: 0,
    right: 0,
    toJSON: () => ({}),
    top,
    width: 0,
    x: 0,
    y: top,
  } as DOMRect
}
