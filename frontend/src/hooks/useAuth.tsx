import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import * as authApi from '../api/auth'
import { ensureCsrfCookie } from '../api/client'
import type { User } from '../types/auth'

export type LogoutStatus = 'idle' | 'pending' | 'blocked'

// Cross-tab logout coordination. A boolean flag can't distinguish "still in
// flight" from "confirmed failed" from "confirmed done", so this stores one
// of LogoutMarker's three values instead (absence of the key means idle).
// Every tab reacts to changes via the window 'storage' event, which fires in
// every OTHER tab automatically - the tab that wrote the value has to update
// its own React state directly since it never receives its own event.
const LOGOUT_STATE_KEY = 'erpv2:logout-state'

type LogoutMarker = 'pending' | 'failed' | 'completed'

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
    const marker = localStorage.getItem(LOGOUT_STATE_KEY) as LogoutMarker | null

    if (marker === 'pending') {
      setLogoutStatus('pending')
      setLoading(false)
      return
    }

    if (marker === 'failed') {
      setLogoutStatus('blocked')
      setLoading(false)
      return
    }

    if (marker === 'completed') {
      // Logout was already confirmed (possibly in another tab, or before a
      // refresh). The session cookie is gone server-side, so there is no
      // point calling /api/me - and doing so risks a race where a stale
      // cookie briefly resurrects the authenticated UI.
      setUser(null)
      setLogoutStatus('idle')
      setLoading(false)
      return
    }

    authApi
      .me()
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    function handleStorage(event: StorageEvent) {
      if (event.key !== LOGOUT_STATE_KEY) {
        return
      }

      const marker = event.newValue as LogoutMarker | null

      if (marker === 'pending') {
        setUser(null)
        setLogoutStatus('pending')
      } else if (marker === 'failed') {
        setUser(null)
        setLogoutStatus('blocked')
      } else if (marker === 'completed') {
        setUser(null)
        setLogoutStatus('idle')
      }
      // marker === null means the key was cleared by a fresh login in
      // another tab; this tab has no session to protect either way, so
      // there is nothing to react to here.
    }

    window.addEventListener('storage', handleStorage)
    return () => window.removeEventListener('storage', handleStorage)
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const loggedInUser = await authApi.login(email, password)
    localStorage.removeItem(LOGOUT_STATE_KEY)
    setLogoutStatus('idle')
    setUser(loggedInUser)
  }, [])

  const performLogout = useCallback(async () => {
    localStorage.setItem(LOGOUT_STATE_KEY, 'pending' satisfies LogoutMarker)
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
        localStorage.setItem(LOGOUT_STATE_KEY, 'failed' satisfies LogoutMarker)
        throw retryError
      }
    }

    setUser(null)
    setLogoutStatus('idle')
    // Left in place (not removed) until the next successful login, so any
    // tab opened or refreshed afterward also stays logged out instead of
    // silently restoring the authenticated UI.
    localStorage.setItem(LOGOUT_STATE_KEY, 'completed' satisfies LogoutMarker)
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
