import { useEffect, useState } from 'react'
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
  const visibleNavItems = navItems.filter((item) => !!user?.role && item.roles.includes(user.role))

  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname])

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
      {sidebarOpen && (
        <button
          type="button"
          aria-label="關閉導覽選單"
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside
        aria-label="主要導覽"
        className={`fixed inset-y-0 left-0 z-40 flex w-56 shrink-0 flex-col bg-sidebar transition-transform duration-200 lg:static lg:translate-x-0 ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex min-h-14 items-center justify-between gap-2 px-4 text-lg font-semibold tracking-tight text-sidebar-fg">
          <span>中古車行系統</span>
          <button
            type="button"
            aria-label="關閉導覽選單"
            className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-sidebar-fg-muted hover:bg-white/5 hover:text-sidebar-fg lg:hidden"
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
                  `flex min-h-11 items-center gap-2 rounded-lg border-l-3 px-3 py-2 text-sm font-medium transition-colors ${
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
            className="flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-border-strong text-fg hover:bg-surface-2 lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu size={20} />
          </button>
          <div className="flex min-w-0 items-center gap-1 sm:gap-3">
            <span className="hidden truncate text-sm text-fg-muted sm:inline">{user?.name}</span>
            <ThemeToggle />
            <button
              onClick={handleLogout}
              className="min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm font-medium text-fg hover:bg-surface-2"
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
