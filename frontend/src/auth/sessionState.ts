export type LogoutStatus = 'idle' | 'pending' | 'blocked'

export const LOGOUT_STATE_KEY = 'erpv2:logout-state'
export const AUTH_SESSION_VERSION_KEY = 'erpv2:auth-session-version'

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
