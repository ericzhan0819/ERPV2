import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { LayoutDashboard, Car, Wallet, Banknote, Users, Contact, ScrollText } from 'lucide-react'
import { useAuth } from '../hooks/useAuth'
import { ThemeToggle } from '../components/ThemeToggle'

const navItems = [
  { to: '/dashboard', label: '總覽', icon: LayoutDashboard, roles: ['admin', 'manager', 'sales'] },
  { to: '/vehicles', label: '車輛', icon: Car, roles: ['admin', 'manager', 'sales'] },
  { to: '/customers', label: '客戶', icon: Contact, roles: ['admin', 'manager', 'sales'] },
  { to: '/money-entries', label: '收支', icon: Wallet, roles: ['admin', 'manager', 'sales'] },
  { to: '/cash-accounts', label: '資金帳戶', icon: Banknote, roles: ['admin', 'manager'] },
  { to: '/users', label: '員工/帳號管理', icon: Users, roles: ['admin'] },
  { to: '/audit-logs', label: '稽核紀錄', icon: ScrollText, roles: ['admin'] },
]

export function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const visibleNavItems = navItems.filter((item) => !!user?.role && item.roles.includes(user.role))

  async function handleLogout() {
    try {
      await logout()
      navigate('/login')
    } catch {
      // Logout failed to be confirmed. ProtectedRoute (which wraps this
      // layout) reacts to the resulting 'blocked' logoutStatus and renders
      // a blocking screen with a retry action instead of this layout, so
      // there is nothing further to do here.
    }
  }

  return (
    <div className="flex min-h-screen bg-bg">
      <aside className="w-56 shrink-0 bg-sidebar">
        <div className="p-4 text-lg font-semibold tracking-tight text-sidebar-fg">中古車行系統</div>
        <nav className="flex flex-col gap-1 px-2">
          {visibleNavItems.map((item) => {
            const Icon = item.icon
            return (
              <NavLink
                key={item.to}
                to={item.to}
                className={({ isActive }) =>
                  `flex items-center gap-2 rounded-lg border-l-3 px-3 py-2 text-sm font-medium transition-colors ${
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
      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-border bg-surface px-6 py-3">
          <div />
          <div className="flex items-center gap-3">
            <span className="text-sm text-fg-muted">{user?.name}</span>
            <ThemeToggle />
            <button
              onClick={handleLogout}
              className="rounded-lg border border-border-strong px-3 py-1.5 text-sm font-medium text-fg hover:bg-surface-2"
            >
              登出
            </button>
          </div>
        </header>
        <main className="flex-1 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
