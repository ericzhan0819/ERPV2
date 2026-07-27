import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { appConfig } from './src/config/app.ts'

const browserTitleFallback = '<title>ERPV2</title>'

function escapeHtmlText(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
}

// Vite 設定文件：https://vite.dev/config/
export default defineConfig({
  plugins: [
    {
      name: 'app-browser-title',
      transformIndexHtml(html) {
        if (!html.includes(browserTitleFallback)) {
          throw new Error('找不到 ERPV2 browser title fallback')
        }

        return html.replace(
          browserTitleFallback,
          `<title>${escapeHtmlText(appConfig.browserTitle)}</title>`,
        )
      },
    },
    react(),
  ],
  server: {
    host: true,
  },
})
