/**
 * The offset belongs to core: an absent one must reach the controller absent, so core's default applies.
 */

import * as converse from '@laravel/converse'
import { cleanup, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { useTranscriptScroll } from '../src/hooks/use-transcript-scroll'

vi.mock('@laravel/converse', async (original) => {
  const actual = await original<typeof converse>()

  return { ...actual, createTranscriptScroll: vi.fn(actual.createTranscriptScroll) }
})

function Harness({ offset }: { offset?: number }) {
  useTranscriptScroll(offset === undefined ? undefined : { offset })

  return null
}

afterEach(cleanup)

it("leaves an absent offset to core's default", () => {
  render(<Harness />)

  const offset = vi.mocked(converse.createTranscriptScroll).mock.calls[0][0].offset as () => number | undefined

  expect(offset()).toBeUndefined()
})

it('passes a given offset through', () => {
  render(<Harness offset={40} />)

  const calls = vi.mocked(converse.createTranscriptScroll).mock.calls

  const offset = calls[calls.length - 1][0].offset as () => number | undefined

  expect(offset()).toBe(40)
})
