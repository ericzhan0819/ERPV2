export type LogoutStatus = 'idle' | 'pending' | 'blocked'

// 用三種登出狀態在分頁間同步；寫入的分頁收不到自己的 storage 事件，必須自行更新 React 狀態。
export const LOGOUT_STATE_KEY = 'erpv2:logout-state'
export const AUTH_SESSION_VERSION_KEY = 'erpv2:auth-session-version'

export interface ExternalLoginStorageDecision {
  handled: boolean
  invalidateMeRequest: boolean
  clearCurrentUser: boolean
}

export function isExternalLoginStorageEvent(
  key: string | null,
  newValue: string | null,
): boolean {
  return (
    key === AUTH_SESSION_VERSION_KEY ||
    (key === LOGOUT_STATE_KEY && newValue === null)
  )
}

export function shouldInvalidateForExternalLogin(
  hasCurrentUser: boolean,
  logoutStatus: LogoutStatus,
): boolean {
  // 登出安全狀態優先；只有仍顯示登入內容的 idle 分頁需要因其他分頁登入而失效。
  return hasCurrentUser && logoutStatus === 'idle'
}

export function decideExternalLoginStorageEvent(
  key: string | null,
  newValue: string | null,
  hasCurrentUser: boolean,
  logoutStatus: LogoutStatus,
): ExternalLoginStorageDecision {
  const handled = isExternalLoginStorageEvent(key, newValue)

  return {
    handled,
    // 即使初次 /api/me 尚未回來、Context 仍是 null，外部登入也必須使舊請求失效。
    invalidateMeRequest: handled,
    clearCurrentUser:
      handled &&
      shouldInvalidateForExternalLogin(hasCurrentUser, logoutStatus),
  }
}
