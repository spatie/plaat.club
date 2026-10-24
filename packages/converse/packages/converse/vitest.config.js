import { defineConfig } from 'vitest/config'

// No DOM environment: the core is framework-free, so a test that needs `document` opts in per file.
export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
  },
})
