import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import * as authApi from '../api/auth'
import { ensureCsrfCookie } from '../api/client'
import type { User } from '../types/auth'

export type LogoutStatus = 'idle' | 'pending' | 'blocked'

// Survives page refresh/close so a logout that never got confirmed (timeout,
// CSRF failure, lost response) can't be silently forgotten: the server
// session cookie may still be valid, so the next load must not restore the
// authenticated UI via /api/me until logout is confirmed.
const LOGOUT_PENDING_KEY = 'erpv2:logout-pending'

interface AuthContextValue {
  user: User | null
  loading: boolean
  logoutStatus: LogoutStatus
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  retryLogout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [logoutStatus, setLogoutStatus] = useState<LogoutStatus>('idle')

  useEffect(() => {
    if (localStorage.getItem(LOGOUT_PENDING_KEY) === '1') {
      setLogoutStatus('blocked')
      setLoading(false)
      return
    }

    authApi
      .me()
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const loggedInUser = await authApi.login(email, password)
    localStorage.removeItem(LOGOUT_PENDING_KEY)
    setLogoutStatus('idle')
    setUser(loggedInUser)
  }, [])

  const performLogout = useCallback(async () => {
    localStorage.setItem(LOGOUT_PENDING_KEY, '1')
    setLogoutStatus('pending')

    try {
      await authApi.logout()
    } catch {
      // The failure may be a stale/expired CSRF token, so refresh it and
      // retry once (the backend logout is idempotent) before giving up.
      try {
        await ensureCsrfCookie()
        await authApi.logout()
      } catch (retryError) {
        setUser(null)
        setLogoutStatus('blocked')
        throw retryError
      }
    }

    localStorage.removeItem(LOGOUT_PENDING_KEY)
    setUser(null)
    setLogoutStatus('idle')
  }, [])

  return (
    <AuthContext.Provider
      value={{ user, loading, logoutStatus, login, logout: performLogout, retryLogout: performLogout }}
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
