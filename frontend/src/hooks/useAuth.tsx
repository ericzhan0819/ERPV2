import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import * as authApi from '../api/auth'
import { ensureCsrfCookie } from '../api/client'
import { onPasswordChangeRequired } from '../auth/passwordChangeRequired'
import {
  applyCurrentUserResponse,
  applyPasswordChangeRequired,
  requireAcceptedCurrentUserResponse,
} from '../auth/currentUserState'
import {
  AUTH_SESSION_VERSION_KEY,
  LOGOUT_STATE_KEY,
  isExternalLoginStorageEvent,
  shouldInvalidateForExternalLogin,
  type LogoutStatus,
} from '../auth/sessionState'
import type {
  CurrentUserPasswordPayload,
  CurrentUserProfilePayload,
  User,
} from '../types/auth'

// 用三種登出狀態在分頁間同步；寫入的分頁收不到自己的 storage 事件，必須自行更新 React 狀態。
// 舊版留下未確認的登出標記時，先轉為新版狀態，避免 /api/me 意外恢復尚未確認登出的工作階段。
const LEGACY_LOGOUT_PENDING_KEY = 'erpv2:logout-pending'

type LogoutMarker = 'pending' | 'failed' | 'completed'

function migrateLegacyLogoutMarker(): LogoutMarker | null {
  const legacy = localStorage.getItem(LEGACY_LOGOUT_PENDING_KEY)
  if (legacy === null) {
    return null
  }

  // 前一頁留下的未完成登出不再有請求可等待，因此保守視為後端未確認。
  const existing = localStorage.getItem(LOGOUT_STATE_KEY) as LogoutMarker | null
  const migrated = existing ?? 'failed'
  if (!existing) {
    localStorage.setItem(LOGOUT_STATE_KEY, migrated satisfies LogoutMarker)
  }
  // 確定已寫入新版標記後，才移除舊版標記。
  localStorage.removeItem(LEGACY_LOGOUT_PENDING_KEY)
  return migrated
}

function createAuthSessionVersion(): string {
  // 這只是讓 storage 值在每次登入時更新的版本，不是安全 token；不依賴僅限 secure context 的 randomUUID。
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

interface AuthContextValue {
  user: User | null
  loading: boolean
  logoutStatus: LogoutStatus
  login: (login: string, password: string) => Promise<void>
  updateProfile: (payload: CurrentUserProfilePayload) => Promise<User>
  updatePassword: (payload: CurrentUserPasswordPayload) => Promise<User>
  logout: () => Promise<void>
  retryLogout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [logoutStatus, setLogoutStatus] = useState<LogoutStatus>('idle')
  const userRef = useRef<User | null>(null)
  const logoutStatusRef = useRef<LogoutStatus>('idle')

  // 登出標記出現後，讓尚在執行的 /api/me 回應失效，避免它重新顯示受保護畫面。
  const meRequestValidRef = useRef(true)

  useEffect(() => {
    const legacyMigratedMarker = migrateLegacyLogoutMarker()
    const marker = legacyMigratedMarker ?? (localStorage.getItem(LOGOUT_STATE_KEY) as LogoutMarker | null)

    if (marker === 'pending') {
      meRequestValidRef.current = false
      logoutStatusRef.current = 'pending'
      setLogoutStatus('pending')
      setLoading(false)
      return
    }

    if (marker === 'failed') {
      meRequestValidRef.current = false
      logoutStatusRef.current = 'blocked'
      setLogoutStatus('blocked')
      setLoading(false)
      return
    }

    if (marker === 'completed') {
      // 登出已確認時不再呼叫 /api/me，避免舊 cookie 的回應短暫恢復登入畫面。
      meRequestValidRef.current = false
      userRef.current = null
      setUser(null)
      logoutStatusRef.current = 'idle'
      setLogoutStatus('idle')
      setLoading(false)
      return
    }

    authApi
      .me()
      .then((fetchedUser) => {
        if (!meRequestValidRef.current) {
          // 請求期間收到登出標記，這份回應已過期，不可恢復登入畫面。
          return
        }
        userRef.current = fetchedUser
        setUser(fetchedUser)
      })
      .catch(() => {
        if (meRequestValidRef.current) {
          userRef.current = null
          setUser(null)
        }
      })
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    function handleStorage(event: StorageEvent) {
      if (
        isExternalLoginStorageEvent(event.key, event.newValue) &&
        shouldInvalidateForExternalLogin(
          userRef.current !== null,
          logoutStatusRef.current,
        )
      ) {
        meRequestValidRef.current = false
        userRef.current = null
        setUser(null)
        return
      }

      if (event.key !== LOGOUT_STATE_KEY || event.newValue === null) {
        return
      }

      const marker = event.newValue as LogoutMarker | null

      if (marker === 'pending') {
        meRequestValidRef.current = false
        userRef.current = null
        setUser(null)
        logoutStatusRef.current = 'pending'
        setLogoutStatus('pending')
      } else if (marker === 'failed') {
        meRequestValidRef.current = false
        userRef.current = null
        setUser(null)
        logoutStatusRef.current = 'blocked'
        setLogoutStatus('blocked')
      } else if (marker === 'completed') {
        meRequestValidRef.current = false
        userRef.current = null
        setUser(null)
        logoutStatusRef.current = 'idle'
        setLogoutStatus('idle')
      }
    }

    window.addEventListener('storage', handleStorage)
    return () => window.removeEventListener('storage', handleStorage)
  }, [])

  useEffect(
    () =>
      onPasswordChangeRequired(() => {
        const result = applyPasswordChangeRequired(
          userRef.current,
          logoutStatusRef.current,
        )
        if (!result.accepted) {
          return
        }

        userRef.current = result.user
        setUser(result.user)
      }),
    [],
  )

  const login = useCallback(async (login: string, password: string) => {
    const loggedInUser = await authApi.login(login, password)
    // 新登入取得的使用者資料最具權威性，先讓本次掛載期間較早的 /api/me 回應失效。
    meRequestValidRef.current = false
    localStorage.setItem(AUTH_SESSION_VERSION_KEY, createAuthSessionVersion())
    localStorage.removeItem(LOGOUT_STATE_KEY)
    logoutStatusRef.current = 'idle'
    setLogoutStatus('idle')
    userRef.current = loggedInUser
    setUser(loggedInUser)
  }, [])

  const updateCurrentUser = useCallback((nextUser: User) => {
    const result = applyCurrentUserResponse(
      userRef.current,
      nextUser,
      logoutStatusRef.current,
    )
    if (!result.accepted) {
      return result
    }

    userRef.current = result.user
    setUser(result.user)
    return result
  }, [])

  const updateProfile = useCallback(
    async (payload: CurrentUserProfilePayload) => {
      const updatedUser = await authApi.updateCurrentUserProfile(payload)
      return requireAcceptedCurrentUserResponse(
        updateCurrentUser(updatedUser),
      )
    },
    [updateCurrentUser],
  )

  const updatePassword = useCallback(
    async (payload: CurrentUserPasswordPayload) => {
      const updatedUser = await authApi.updateCurrentUserPassword(payload)
      return requireAcceptedCurrentUserResponse(
        updateCurrentUser(updatedUser),
      )
    },
    [updateCurrentUser],
  )

  const performLogout = useCallback(async () => {
    // 本分頁不會收到自己的 storage 事件，因此在此直接讓既有 /api/me 請求失效。
    meRequestValidRef.current = false
    localStorage.setItem(LOGOUT_STATE_KEY, 'pending' satisfies LogoutMarker)
    logoutStatusRef.current = 'pending'
    setLogoutStatus('pending')

    try {
      await authApi.logout()
    } catch {
      // 可能是 CSRF token 過期，先更新 token 並重試一次；登出 API 可安全重複呼叫。
      try {
        await ensureCsrfCookie()
        await authApi.logout()
      } catch (retryError) {
        userRef.current = null
        setUser(null)
        logoutStatusRef.current = 'blocked'
        setLogoutStatus('blocked')
        localStorage.setItem(LOGOUT_STATE_KEY, 'failed' satisfies LogoutMarker)
        throw retryError
      }
    }

    userRef.current = null
    setUser(null)
    logoutStatusRef.current = 'idle'
    setLogoutStatus('idle')
    // 保留完成標記到下次成功登入，讓之後開啟或重新整理的分頁也維持登出狀態。
    localStorage.setItem(LOGOUT_STATE_KEY, 'completed' satisfies LogoutMarker)
  }, [])

  return (
    <AuthContext.Provider
      value={{
        user,
        loading,
        logoutStatus,
        login,
        updateProfile,
        updatePassword,
        logout: performLogout,
        retryLogout: performLogout,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
