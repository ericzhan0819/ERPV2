import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'

type Theme = 'light' | 'dark'

interface ThemeContextValue {
  theme: Theme
  toggleTheme: () => void
  setViewportDimmed: (dimmed: boolean) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

const STORAGE_KEY = 'erp-theme'
const THEME_COLORS: Record<Theme, string> = {
  light: '#F8FAFC',
  dark: '#0B1120',
}
const DIMMED_THEME_COLORS: Record<Theme, string> = {
  light: '#7C7D7E',
  dark: '#060910',
}

function getInitialTheme(): Theme {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark') return stored
  } catch {
    // Safari 限制儲存空間時仍依系統主題正常載入。
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function syncDocumentTheme(theme: Theme, refreshThemeColor = false, dimmed = false) {
  const isDark = theme === 'dark'
  const backgroundColor = dimmed ? DIMMED_THEME_COLORS[theme] : THEME_COLORS[theme]
  const root = document.documentElement

  root.classList.toggle('dark', isDark)
  root.style.colorScheme = theme
  root.style.backgroundColor = backgroundColor
  document.body.style.backgroundColor = backgroundColor
  document.getElementById('root')?.style.setProperty('background-color', backgroundColor)

  const themeColor = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')
  if (!themeColor) return

  themeColor.setAttribute('content', backgroundColor)

  if (refreshThemeColor && themeColor.parentNode) {
    // iOS Safari 偶爾會快取瀏覽器上下區域；重新掛載 meta 讓它立即重繪。
    themeColor.parentNode.replaceChild(themeColor.cloneNode(true), themeColor)
  }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<Theme>(getInitialTheme)
  const viewportDimmedRef = useRef(false)
  const refreshTheme = useCallback(() => {
    syncDocumentTheme(theme, true, viewportDimmedRef.current)
  }, [theme])
  const setViewportDimmed = useCallback((dimmed: boolean) => {
    viewportDimmedRef.current = dimmed
    syncDocumentTheme(theme, true, dimmed)
  }, [theme])

  useLayoutEffect(() => {
    syncDocumentTheme(theme, true, viewportDimmedRef.current)

    try {
      localStorage.setItem(STORAGE_KEY, theme)
    } catch {
      // 無法持久化時不影響本次主題與 Safe Area 底色。
    }
  }, [theme])

  useEffect(() => {
    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') refreshTheme()
    }

    window.addEventListener('pageshow', refreshTheme)
    window.addEventListener('orientationchange', refreshTheme)
    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      window.removeEventListener('pageshow', refreshTheme)
      window.removeEventListener('orientationchange', refreshTheme)
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [refreshTheme])

  function toggleTheme() {
    setTheme((prev) => (prev === 'dark' ? 'light' : 'dark'))
  }

  return <ThemeContext.Provider value={{ theme, toggleTheme, setViewportDimmed }}>{children}</ThemeContext.Provider>
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
