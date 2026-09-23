import { Navigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { UserRole } from '../types/user'
import { useAuth } from '../hooks/useAuth'
import { decideProtectedRoute } from '../auth/protectedRouteDecision'

export function ProtectedRoute({
  children,
  allowedRoles,
  passwordChangeOnly = false,
}: {
  children: ReactNode
  allowedRoles?: UserRole[]
  passwordChangeOnly?: boolean
}) {
  const { user, loading, logoutStatus, retryLogout } = useAuth()
  const decision = decideProtectedRoute({
    user,
    loading,
    logoutStatus,
    allowedRoles,
    passwordChangeOnly,
  })

  if (decision.type === 'loading') {
    return <div className="flex min-h-screen items-center justify-center text-fg-muted">載入中...</div>
  }

  if (decision.type === 'logout-pending') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <p className="text-sm text-fg-muted">登出中，請稍候...</p>
      </div>
    )
  }

  if (decision.type === 'logout-blocked') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <div className="max-w-sm rounded-2xl border border-border bg-surface p-8 text-center shadow-sm">
          <p className="text-sm text-fg-muted">
            登出尚未完成。為保護資料，後台畫面已關閉。請重試登出；若使用共用電腦，請關閉瀏覽器。
          </p>
          <button
            onClick={() => {
              retryLogout().catch(() => {})
            }}
            className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
          >
            重試登出
          </button>
        </div>
      </div>
    )
  }

  if (decision.type === 'redirect') {
    return <Navigate to={decision.to} replace />
  }

  return <>{children}</>
}
