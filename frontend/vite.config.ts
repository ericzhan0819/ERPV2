import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Vite 設定文件：https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
  },
})
