import type { User } from '../types/user'
import {
  isCurrentAuthSessionRequest,
  type AuthRequestGeneration,
  type LogoutStatus,
} from './sessionState'

export interface CurrentUserUpdateResult {
  accepted: boolean
  user: User | null
}

export class StaleCurrentUserResponseError extends Error {
  constructor() {
    super('目前登入狀態已變更，忽略過期的使用者回應')
    this.name = 'StaleCurrentUserResponseError'
  }
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

export function applyPasswordChangeRequired(
  currentUser: User | null,
  logoutStatus: LogoutStatus,
  requestGeneration: AuthRequestGeneration,
  currentGeneration: AuthRequestGeneration,
): CurrentUserUpdateResult {
  if (
    logoutStatus !== 'idle' ||
    !currentUser ||
    currentUser.must_change_password ||
    !isCurrentAuthSessionRequest(
      requestGeneration,
      currentGeneration,
    )
  ) {
    return { accepted: false, user: currentUser }
  }

  return {
    accepted: true,
    user: { ...currentUser, must_change_password: true },
  }
}

export function applyAuthSessionInvalidated(
  currentUser: User | null,
  logoutStatus: LogoutStatus,
  requestGeneration: AuthRequestGeneration,
  currentGeneration: AuthRequestGeneration,
): CurrentUserUpdateResult {
  if (
    logoutStatus !== 'idle' ||
    !isCurrentAuthSessionRequest(
      requestGeneration,
      currentGeneration,
    )
  ) {
    return { accepted: false, user: currentUser }
  }

  return { accepted: true, user: null }
}

export function requireAcceptedCurrentUserResponse(
  result: CurrentUserUpdateResult,
): User {
  if (!result.accepted || !result.user) {
    throw new StaleCurrentUserResponseError()
  }

  return result.user
}
