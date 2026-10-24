import type { ChatStore } from './chat'

/**
 * The store the composer sends to, and what it does once a turn is on its way.
 */
export type ChatComposerConfig = {
  chat: ChatStore
  beginTurn?: () => void
}

/**
 * The input area's state and rules.
 */
export type ChatComposer = {
  getDraft: () => string
  setDraft: (value: string) => void
  submit: (message?: string) => void
  clear: () => void
  subscribe: (listener: () => void) => () => void
}

/**
 * The input area's state and rules, with no framework and no DOM.
 */
export class Composer implements ChatComposer {
  /**
   * The listeners to notify when the draft changes.
   */
  protected listeners = new Set<() => void>()

  /**
   * What the reader has typed.
   */
  protected draft = ''

  /**
   * Create a new composer.
   */
  constructor(protected config: ChatComposerConfig) {
    // `ChatComposer` is a bag of callables, so the public methods keep their receiver.
    this.getDraft = this.getDraft.bind(this)
    this.setDraft = this.setDraft.bind(this)
    this.submit = this.submit.bind(this)
    this.clear = this.clear.bind(this)
    this.subscribe = this.subscribe.bind(this)
  }

  /**
   * Get the current draft.
   */
  public getDraft(): string {
    return this.draft
  }

  /**
   * Set the draft, notifying the listeners when it changed.
   */
  public setDraft(value: string): void {
    if (value === this.draft) {
      return
    }

    this.draft = value
    this.notify()
  }

  /**
   * Send the draft, or the given message, handing a draft the store did not keep back to the reader.
   */
  public submit(message?: string): void {
    const fromDraft = message === undefined
    const content = fromDraft ? this.draft : message

    if (content.trim() === '') {
      return
    }

    // An explicit message was never typed here, so only a sent draft is emptied.
    if (fromDraft) {
      this.clear()
    }

    // Read before sending: `send()` starts the run, so asking afterwards always says yes.
    const queueing = this.config.chat.getState().busy

    void this.config.chat.send(content).then((kept) => this.restoreDraft(kept, fromDraft, content))

    if (!queueing) {
      this.config.beginTurn?.()
    }
  }

  /**
   * Put a sent draft the store did not keep back, unless the reader has started a new one.
   */
  protected restoreDraft(kept: boolean, fromDraft: boolean, content: string): void {
    if (!kept && fromDraft && this.draft === '') {
      this.setDraft(content)
    }
  }

  /**
   * Empty the draft.
   */
  public clear(): void {
    this.setDraft('')
  }

  /**
   * Listen for changes to the draft.
   */
  public subscribe(listener: () => void): () => void {
    this.listeners.add(listener)

    return () => {
      this.listeners.delete(listener)
    }
  }

  /**
   * Notify the listeners.
   */
  protected notify(): void {
    for (const listener of this.listeners) {
      listener()
    }
  }
}

/**
 * Create a new composer.
 */
export function createChatComposer(config: ChatComposerConfig): ChatComposer {
  return new Composer(config)
}
