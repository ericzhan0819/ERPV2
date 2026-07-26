import { ThemeToggle } from '../components/ThemeToggle'
import { useAuth } from '../hooks/useAuth'

export function PasswordChangeRequiredPlaceholder() {
  const { logout } = useAuth()

  function handleLogout() {
    logout().catch(() => {
      // ProtectedRoute 會依 blocked 狀態顯示可重試的安全畫面。
    })
  }

  return (
    <div className="app-shell flex min-w-0 bg-bg">
      <main className="app-main flex w-full items-center justify-center pt-[calc(1rem+env(safe-area-inset-top,0px))]">
        <section className="w-full max-w-md rounded-2xl border border-border bg-surface p-6 text-center shadow-sm sm:p-8">
          <div className="flex justify-end">
            <ThemeToggle />
          </div>
          <h1 className="mt-4 text-2xl font-semibold tracking-tight text-fg">
            請先修改密碼
          </h1>
          <p className="mt-3 text-sm leading-6 text-fg-muted">
            此帳號目前使用管理員建立或重設的預設密碼，完成修改前無法進入營運功能。修改密碼表單準備完成前，請先登出。
          </p>
          <button
            type="button"
            onClick={handleLogout}
            className="mt-6 min-h-11 w-full rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
          >
            登出
          </button>
        </section>
      </main>
    </div>
  )
}
