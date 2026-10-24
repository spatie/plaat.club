import { resolve } from 'path'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import dts from 'vite-plugin-dts'

export default defineConfig({
  plugins: [
    vue(),
    dts({
      insertTypesEntry: true,
      rollupTypes: true,
      include: ['src/**/*.ts', 'src/**/*.vue'],
    }),
  ],
  build: {
    lib: {
      entry: resolve(__dirname, 'src/index.ts'),
      formats: ['es'],
      fileName: 'index',
    },
    rollupOptions: {
      // A dependency rather than a bundled copy, so an application resolves one instance of the chat state.
      external: ['vue', '@laravel/converse'],
    },
  },
})
