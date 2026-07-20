import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { LayoutDashboard, Car, Wallet, Banknote, Users, Contact, ScrollText, HandCoins, Menu, X } from 'lucide-react'
import { useAuth } from '../hooks/useAuth'
import { ThemeToggle } from '../components/ThemeToggle'

const navItems = [
  { to: '/dashboard', label: '總覽', icon: LayoutDashboard, roles: ['admin', 'manager', 'sales'] },
  { to: '/vehicles', label: '車輛', icon: Car, roles: ['admin', 'manager', 'sales'] },
  { to: '/customers', label: '客戶', icon: Contact, roles: ['admin', 'manager', 'sales'] },
  { to: '/money-entries', label: '收支', icon: Wallet, roles: ['admin', 'manager', 'sales'] },
  { to: '/cash-accounts', label: '資金帳戶', icon: Banknote, roles: ['admin', 'manager'] },
  { to: '/users', label: '員工/帳號管理', icon: Users, roles: ['admin'] },
  { to: '/salary', label: '薪資結算', icon: HandCoins, roles: ['admin'] },
  { to: '/audit-logs', label: '稽核紀錄', icon: ScrollText, roles: ['admin'] },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [isMobileViewport, setIsMobileViewport] = useState(true)
  const sidebarRef = useRef<HTMLElement>(null)
  const sidebarCloseButtonRef = useRef<HTMLButtonElement>(null)
  const visibleNavItems = navItems.filter((item) => !!user?.role && item.roles.includes(user.role))

  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname])

  useLayoutEffect(() => {
    let resizeFrame: number | null = null

    function syncViewportFromSidebar() {
      if (!sidebarRef.current) return

      // 刻意讀取 Tailwind lg:static 已套用的結果，避免 JS breakpoint 在非預設字級下失步；
      // 若變更 aside 的 lg:static 或 mobile position，必須同步調整此判斷。
      const isMobile = window.getComputedStyle(sidebarRef.current).position === 'fixed'
      setIsMobileViewport(isMobile)
      if (!isMobile) setSidebarOpen(false)
    }

    function scheduleViewportSync() {
      if (resizeFrame !== null) return

      resizeFrame = window.requestAnimationFrame(() => {
        resizeFrame = null
        syncViewportFromSidebar()
      })
    }

    syncViewportFromSidebar()
    window.addEventListener('resize', scheduleViewportSync)
    return () => {
      window.removeEventListener('resize', scheduleViewportSync)
      if (resizeFrame !== null) window.cancelAnimationFrame(resizeFrame)
    }
  }, [])

  useEffect(() => {
    if (!sidebarOpen || !isMobileViewport) return

    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const previousBodyOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    sidebarCloseButtonRef.current?.focus()

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setSidebarOpen(false)
        return
      }

      if (event.key !== 'Tab' || !sidebarRef.current) return

      const focusableElements = Array.from(
        sidebarRef.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      )
      const firstElement = focusableElements[0]
      const lastElement = focusableElements.at(-1)

      if (!firstElement || !lastElement) return

      if (!sidebarRef.current.contains(document.activeElement)) {
        event.preventDefault()
        firstElement.focus()
        return
      }

      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousBodyOverflow
      if (previouslyFocused?.isConnected) previouslyFocused.focus()
    }
  }, [isMobileViewport, sidebarOpen])

  async function handleLogout() {
    try {
      await logout()
      navigate('/login')
    } catch {
      // 登出尚未由後端確認時，ProtectedRoute 會依 blocked 狀態顯示可重試的阻擋畫面，
      // 所以此處不再額外處理。
    }
  }

  return (
    <div className="flex min-h-screen min-w-0 bg-bg">
      {sidebarOpen && isMobileViewport && (
        <div
          aria-hidden="true"
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside
        ref={sidebarRef}
        id="app-sidebar"
        aria-label="主要導覽"
        aria-hidden={isMobileViewport && !sidebarOpen ? true : undefined}
        inert={isMobileViewport && !sidebarOpen ? true : undefined}
        className={`fixed inset-y-0 left-0 z-40 flex w-56 shrink-0 flex-col bg-sidebar transition-transform duration-200 lg:static lg:translate-x-0 ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex min-h-14 items-center justify-between gap-2 px-4 text-lg font-semibold tracking-tight text-sidebar-fg">
          <span>中古車行系統</span>
          <button
            ref={sidebarCloseButtonRef}
            type="button"
            aria-label="關閉導覽選單"
            className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-sidebar-fg-muted hover:bg-white/5 hover:text-sidebar-fg focus:outline-none focus:ring-2 focus:ring-inset focus:ring-sidebar-ring lg:hidden"
            onClick={() => setSidebarOpen(false)}
          >
            <X size={20} />
          </button>
        </div>
        <nav className="flex flex-col gap-1 px-2">
          {visibleNavItems.map((item) => {
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                className={({ isActive }) =>
                  `flex min-h-11 items-center gap-2 rounded-lg border-l-3 px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sidebar-ring ${
                    isActive
                      ? 'border-primary bg-primary/20 text-sidebar-fg'
                      : 'border-transparent text-sidebar-fg-muted hover:bg-white/5 hover:text-sidebar-fg'
                  }`
                }
              >
                <Icon size={16} />
                {item.label}
              </NavLink>
            )
          })}
        </nav>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex min-h-14 items-center justify-between gap-2 border-b border-border bg-surface px-3 py-2 sm:px-6">
          <button
            type="button"
            aria-label="開啟導覽選單"
            aria-expanded={sidebarOpen}
            aria-controls="app-sidebar"
            className="flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-border-strong text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu size={20} />
          </button>
          <div className="flex min-w-0 items-center gap-1 sm:gap-3">
            <span className="hidden truncate text-sm text-fg-muted sm:inline">{user?.name}</span>
            <ThemeToggle />
            <button
              onClick={handleLogout}
              className="min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm font-medium text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
            >
              登出
            </button>
          </div>
        </header>
        <main className="min-w-0 flex-1 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
