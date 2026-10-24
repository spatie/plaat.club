import { resolve } from 'path'
import { defineConfig } from 'vitest/config'

/*
 * A DOM environment, unlike the other packages: `useSyncExternalStore` and
 * `useLayoutEffect` are what this adapter is, and neither runs without one.
 *
 * Core resolves to its source rather than its build, so a stale `dist` cannot
 * make these pass or fail for the wrong reason.
 */
export default defineConfig({
  esbuild: {
    jsx: 'automatic',
  },
  resolve: {
    alias: {
      '@laravel/converse': resolve(import.meta.dirname, '../converse/src/index.ts'),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.tsx'],
  },
})
