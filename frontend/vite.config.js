import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const proxy = {
    '/api': { target: env.BACKEND_URL || 'http://127.0.0.1:8000', changeOrigin: true },
    '/sanctum': { target: env.BACKEND_URL || 'http://127.0.0.1:8000', changeOrigin: true },
  }
  return {
    plugins: [react(), tailwindcss()],
    server: { host: '127.0.0.1', port: 5174, strictPort: true, proxy },
    preview: { host: '127.0.0.1', port: 4173, strictPort: true, proxy },
  }
})
