import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

const navItems = [
  { to: '/dashboard', label: '總覽' },
  { to: '/vehicles', label: '車輛' },
  { to: '/money-entries', label: '收支' },
  { to: '/cash-accounts', label: '資金帳戶' },
  { to: '/users', label: '使用者' },
]

export function AppLayout() {
  const { user, logout, loggingOut } = useAuth()
  const navigate = useNavigate()

  async function handleLogout() {
    try {
      await logout()
      navigate('/login')
    } catch {
      // The client-side auth state is already cleared by useAuth's logout()
      // regardless of outcome, so route away and surface a warning instead
      // of leaving protected data on screen behind a rejected promise.
      navigate('/login', {
        state: { logoutWarning: '登出請求未確認，為保護資料已關閉後台畫面。若使用共用電腦，請關閉瀏覽器。' },
      })
    }
  }

  if (loggingOut) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <p className="text-sm text-gray-500">登出中，請稍候...</p>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="w-56 shrink-0 border-r border-gray-200 bg-white">
        <div className="p-4 text-lg font-semibold text-gray-900">中古車行系統</div>
        <nav className="flex flex-col gap-1 px-2">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                `rounded-lg px-3 py-2 text-sm font-medium ${
                  isActive ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'
                }`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-3">
          <div />
          <div className="flex items-center gap-3">
            <span className="text-sm text-gray-600">{user?.name}</span>
            <button
              onClick={handleLogout}
              className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
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
