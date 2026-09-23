import { Moon, Sun } from 'lucide-react'
import { useTheme } from '../hooks/useTheme'

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()
  const isDark = theme === 'dark'

  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={isDark ? '切換為淺色模式' : '切換為深色模式'}
      title={isDark ? '切換為淺色模式' : '切換為深色模式'}
      className="flex h-11 w-11 items-center justify-center rounded-md border border-border text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {isDark ? <Sun size={18} /> : <Moon size={18} />}
    </button>
  )
}
