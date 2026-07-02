import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import * as authApi from '../api/auth'
import type { User } from '../types/auth'

interface AuthContextValue {
  user: User | null
  loading: boolean
  loggingOut: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [loggingOut, setLoggingOut] = useState(false)

  useEffect(() => {
    authApi
      .me()
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const loggedInUser = await authApi.login(email, password)
    setUser(loggedInUser)
  }, [])

  const logout = useCallback(async () => {
    setLoggingOut(true)
    try {
      await authApi.logout()
    } finally {
      // Regardless of whether the server confirmed the logout (success,
      // timeout, CSRF failure, or lost response), the client must stop
      // treating this session as authenticated so protected data is never
      // left on screen after a logout was requested.
      setUser(null)
      setLoggingOut(false)
    }
  }, [])

  return (
    <AuthContext.Provider value={{ user, loading, loggingOut, login, logout }}>{children}</AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
