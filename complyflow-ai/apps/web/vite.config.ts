import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    proxy: {
      '/api': { target: process.env.API_PROXY_TARGET || 'http://api:8000', changeOrigin: true },
      '/sanctum': { target: process.env.API_PROXY_TARGET || 'http://api:8000', changeOrigin: true },
    },
  },
  test: { environment: 'jsdom', setupFiles: ['./src/test/setup.ts'], clearMocks: true },
})
