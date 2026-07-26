import type { User } from '../types/user'
import type { LogoutStatus } from './sessionState'

export interface CurrentUserUpdateResult {
  accepted: boolean
  user: User | null
}

export function applyCurrentUserResponse(
  currentUser: User | null,
  nextUser: User,
  logoutStatus: LogoutStatus,
): CurrentUserUpdateResult {
  // API 回應只能更新同一個仍在登入中的使用者；登出期間或其他帳號的過期回應一律捨棄。
  if (
    logoutStatus !== 'idle' ||
    !currentUser ||
    currentUser.id !== nextUser.id
  ) {
    return { accepted: false, user: currentUser }
  }

  return { accepted: true, user: nextUser }
}
