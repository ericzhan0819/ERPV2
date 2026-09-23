import type { User, UserRole } from '../types/user'
import { PASSWORD_CHANGE_REQUIRED_PATH } from './passwordChangeRequired'
import type { LogoutStatus } from './sessionState'

export type ProtectedRouteDecision =
  | { type: 'render' }
  | { type: 'loading' }
  | { type: 'logout-pending' }
  | { type: 'logout-blocked' }
  | { type: 'redirect'; to: string }

interface ProtectedRouteState {
  user: User | null
  loading: boolean
  logoutStatus: LogoutStatus
  allowedRoles?: UserRole[]
  passwordChangeOnly?: boolean
}

export function decideProtectedRoute({
  user,
  loading,
  logoutStatus,
  allowedRoles,
  passwordChangeOnly = false,
}: ProtectedRouteState): ProtectedRouteDecision {
  if (loading) {
    return { type: 'loading' }
  }

  if (logoutStatus === 'pending') {
    return { type: 'logout-pending' }
  }

  if (logoutStatus === 'blocked') {
    return { type: 'logout-blocked' }
  }

  if (!user) {
    return { type: 'redirect', to: '/login' }
  }

  if (user.must_change_password) {
    return passwordChangeOnly
      ? { type: 'render' }
      : { type: 'redirect', to: PASSWORD_CHANGE_REQUIRED_PATH }
  }

  if (passwordChangeOnly) {
    return { type: 'redirect', to: '/dashboard' }
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return { type: 'redirect', to: '/dashboard' }
  }

  return { type: 'render' }
}
