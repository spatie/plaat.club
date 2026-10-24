/**
 * The attribute that marks the prompt to anchor on.
 */
export const promptAttribute = 'data-prompt'

/**
 * The reader gestures that end the hold.
 */
const GESTURES = ['wheel', 'touchmove', 'mousedown', 'keydown'] as const

/**
 * How long the glide to a new turn takes, in milliseconds.
 */
const DURATION = 320

/**
 * The room left above the anchored prompt, in pixels.
 */
const DEFAULT_OFFSET = 24

/**
 * The offset to anchor at, and the callback that renders the spacer.
 */
export type TranscriptScrollOptions = {
  offset?: number | null | (() => number | null | undefined)
  onReserve: (height: number) => void
}

/**
 * What an adapter may ask of the transcript's scroll position.
 */
export type TranscriptScrollController = {
  attach: (element: HTMLElement | null) => void
  beginTurn: (afterRender: () => Promise<void>) => Promise<void>
}

/**
 * What the transcript is doing with the scroll position.
 */
type ScrollMode =
  | { kind: 'idle' }
  | { kind: 'bottom' }
  | { kind: 'anchored'; anchor: number; offset: number; held: number | null }

/**
 * Get the element's `scroll-padding-top` in pixels, or null while it is `auto`.
 */
function scrollPaddingTop(element: HTMLElement): number | null {
  const padding = getComputedStyle(element).scrollPaddingTop

  if (padding === '' || padding === 'auto') {
    return null
  }

  return /^-?[\d.]+px$/.test(padding) ? parseFloat(padding) : resolveHeight(padding, element.clientHeight)
}

/**
 * Let the browser resolve a CSS length, such as a `calc()` mixing units, against a percentage base.
 */
function resolveHeight(value: string, base: number): number | null {
  const frame = document.createElement('div')
  const probe = document.createElement('div')

  // `all: initial` keeps the page's own styles, such as padding on every `div`, out of the measurement.
  frame.style.cssText = `all: initial; display: block; position: absolute; visibility: hidden; top: 0; left: 0; width: 0; height: ${base}px`
  probe.style.cssText = 'all: initial; display: block'
  probe.style.height = value
  frame.append(probe)
  document.body.append(frame)

  const height = probe.style.height === '' ? null : probe.getBoundingClientRect().height

  frame.remove()

  return height
}

/**
 * Get a child's offset from the top of the element that scrolls it.
 */
function offsetWithin(element: HTMLElement, child: HTMLElement): number {
  return child.getBoundingClientRect().top - element.getBoundingClientRect().top + element.scrollTop
}

/**
 * Anchors the newest prompt near the top of the transcript and holds it there.
 */
export class TranscriptScroll implements TranscriptScrollController {
  /**
   * The offset given in place of the element's `scroll-padding-top`.
   */
  protected offset: TranscriptScrollOptions['offset']

  /**
   * The callback that renders the spacer.
   */
  protected onReserve: (height: number) => void

  /**
   * The height the spacer was last given.
   */
  protected spacer = 0

  /**
   * What the transcript is doing with the scroll position.
   */
  protected mode: ScrollMode = { kind: 'idle' }

  /**
   * The in-flight scroll animation, if any.
   */
  protected animation: number | null = null

  /**
   * The observer that watches the transcript for arriving content.
   */
  protected growth: MutationObserver | null = null

  /**
   * The element being scrolled.
   */
  protected watched: HTMLElement | null = null

  /**
   * Create a new transcript scroll controller.
   */
  constructor({ offset, onReserve }: TranscriptScrollOptions) {
    this.offset = offset
    this.onReserve = onReserve

    // `TranscriptScrollController` is a bag of callables, so these keep their receiver.
    this.attach = this.attach.bind(this)
    this.beginTurn = this.beginTurn.bind(this)
  }

  /**
   * Watch an element, or stop watching the one this controller has.
   */
  public attach(element: HTMLElement | null): void {
    if (this.watched === element) {
      return
    }

    this.cancel()
    this.growth?.disconnect()
    this.growth = null

    for (const gesture of GESTURES) {
      this.watched?.removeEventListener(gesture, this.release)
    }

    this.watched = element

    // A new element inherits nothing: the anchor and the hold were measured inside the old one.
    this.mode = element ? { kind: 'bottom' } : { kind: 'idle' }
    this.reserveSpacer(0)

    if (!element) {
      return
    }

    for (const gesture of GESTURES) {
      element.addEventListener(gesture, this.release, { passive: true })
    }

    // The spacer's height is an inline style, an attribute change, which this does not observe.
    this.growth = new MutationObserver(() => this.reserve(element))
    this.growth.observe(element, {
      childList: true,
      subtree: true,
      characterData: true,
    })

    element.scrollTop = element.scrollHeight - element.clientHeight
  }

  /**
   * Anchor the newest prompt and glide to it.
   */
  public async beginTurn(afterRender: () => Promise<void>): Promise<void> {
    const element = this.watched

    if (!element) {
      return
    }

    // The prompt is added in the same tick as this call, so measuring now anchors the previous turn.
    await afterRender()

    // `attach()` can land across either await, and the element it watches now has a mode of its own.
    if (this.watched !== element) {
      return
    }

    const prompt = this.newestPrompt(element)

    if (!prompt) {
      return
    }

    const anchor = offsetWithin(element, prompt)
    const offset = this.resolveOffset(element)

    this.mode = { kind: 'anchored', anchor, offset, held: null }

    // Reserve first: the target is only reachable once the room exists.
    this.reserveSpacer(element.clientHeight)

    await afterRender()

    if (this.watched !== element) {
      return
    }

    this.reserve(element)
    this.glideTo(element, anchor - offset)
  }

  /**
   * Get the room to leave above the prompt: the given offset, the element's `scroll-padding-top`, or the default.
   */
  protected resolveOffset(element: HTMLElement): number {
    const offset = typeof this.offset === 'function' ? this.offset() : this.offset

    return Math.max(0, offset ?? scrollPaddingTop(element) ?? DEFAULT_OFFSET)
  }

  /**
   * Get the prompt of the newest turn.
   */
  protected newestPrompt(element: HTMLElement): HTMLElement | undefined {
    const prompts = element.querySelectorAll<HTMLElement>(`[${promptAttribute}]`)

    return prompts[prompts.length - 1]
  }

  /**
   * Size the spacer to the room the anchored prompt still needs.
   */
  protected reserve(element: HTMLElement): void {
    const mode = this.mode

    if (mode.kind === 'bottom') {
      element.scrollTop = element.scrollHeight - element.clientHeight

      return
    }

    if (mode.kind !== 'anchored') {
      return
    }

    const content = this.content(element)

    // A thread that fits the box is wholly on screen, so there is nothing to anchor and nothing to reserve.
    const room = content <= element.clientHeight ? 0 : element.clientHeight - (content - mode.anchor) - mode.offset

    this.reserveSpacer(Math.max(0, room))

    this.keepHeld(element, mode.held)
  }

  /**
   * Give the spacer a height.
   */
  protected reserveSpacer(height: number): void {
    this.spacer = height
    this.onReserve(height)
  }

  /**
   * Get the height of the transcript without the spacer.
   */
  protected content(element: HTMLElement): number {
    return element.scrollHeight - this.spacer
  }

  /**
   * Keep the held position as the spacer is retrimmed.
   */
  protected keepHeld(element: HTMLElement, held: number | null): void {
    if (held === null) {
      return
    }

    const target = Math.min(held, element.scrollHeight - element.clientHeight)

    if (Math.abs(element.scrollTop - target) > 1) {
      element.scrollTop = target
    }
  }

  /**
   * Animate to a position, then hold it.
   */
  protected glideTo(element: HTMLElement, top: number): void {
    // Hand-rolled: the browser abandons a smooth scroll whenever the content resizes.
    this.cancel()

    const from = element.scrollTop
    const distance = top - from

    if (Math.abs(distance) < 2) {
      this.hold(top)

      return
    }

    const started = performance.now()

    const step = (now: number): void => {
      const progress = Math.min(1, (now - started) / DURATION)

      element.scrollTop = from + distance * (1 - (1 - progress) ** 3)

      if (progress < 1) {
        this.animation = requestAnimationFrame(step)

        return
      }

      this.animation = null
      this.hold(top)
    }

    this.animation = requestAnimationFrame(step)
  }

  /**
   * Hold the position the glide arrived at.
   */
  protected hold(top: number): void {
    // A frame lands a turn late, and whatever re-attached or released in the meantime owns the mode now.
    if (this.mode.kind !== 'anchored') {
      return
    }

    this.mode = { ...this.mode, held: top }
  }

  /**
   * Cancel the in-flight scroll animation.
   */
  protected cancel(): void {
    if (this.animation !== null) {
      cancelAnimationFrame(this.animation)
      this.animation = null
    }
  }

  /**
   * Stop writing the scroll position once the reader takes over.
   */
  protected release = (): void => {
    // An anchored turn stays anchored: the spacer keeps being retrimmed, only the scroll writes stop.
    this.mode = this.mode.kind === 'anchored' ? { ...this.mode, held: null } : { kind: 'idle' }
    this.cancel()
  }
}

/**
 * Create a new transcript scroll controller.
 */
export function createTranscriptScroll(options: TranscriptScrollOptions): TranscriptScrollController {
  return new TranscriptScroll(options)
}
