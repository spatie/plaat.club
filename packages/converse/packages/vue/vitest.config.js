import { resolve } from 'path'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

/*
 * No DOM environment by default: `useChat` runs inside an `effectScope` and the transport is a plain
 * function, so nothing here needs to mount. Files that need one say so with `@vitest-environment jsdom`,
 * and `useChatSsr.test.ts` depends on the default, since a server render is only honest without `window`.
 *
 * Core resolves to its source rather than its build, so a stale `dist` cannot make these pass or fail.
 */
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@laravel/converse': resolve(import.meta.dirname, '../converse/src/index.ts'),
    },
  },
  test: {
    include: ['tests/**/*.test.ts'],
  },
})
