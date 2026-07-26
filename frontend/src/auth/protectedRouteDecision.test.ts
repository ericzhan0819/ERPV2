import { describe, expect, it } from 'vitest'
import type { User } from '../types/user'
import { PASSWORD_CHANGE_REQUIRED_PATH } from './passwordChangeRequired'
import { decideProtectedRoute } from './protectedRouteDecision'

const user: User = {
  id: 1,
  name: '王小明',
  email: 'owner@example.com',
  username: 'owner',
  must_change_password: false,
  role: 'admin',
  is_admin: true,
  is_active: true,
  phone: null,
  job_title: null,
  hire_date: null,
  notes: null,
}

describe('protected route decision', () => {
  it('redirects unauthenticated users to login', () => {
    expect(
      decideProtectedRoute({
        user: null,
        loading: false,
        logoutStatus: 'idle',
      }),
    ).toEqual({ type: 'redirect', to: '/login' })
  })

  it('redirects operational routes when login or me reports a required password change', () => {
    expect(
      decideProtectedRoute({
        user: { ...user, must_change_password: true },
        loading: false,
        logoutStatus: 'idle',
      }),
    ).toEqual({ type: 'redirect', to: PASSWORD_CHANGE_REQUIRED_PATH })
  })

  it('allows the dedicated password route without a redirect loop', () => {
    expect(
      decideProtectedRoute({
        user: { ...user, must_change_password: true },
        loading: false,
        logoutStatus: 'idle',
        passwordChangeOnly: true,
      }),
    ).toEqual({ type: 'render' })
  })

  it('sends completed users away from the dedicated password route', () => {
    expect(
      decideProtectedRoute({
        user,
        loading: false,
        logoutStatus: 'idle',
        passwordChangeOnly: true,
      }),
    ).toEqual({ type: 'redirect', to: '/dashboard' })
  })

  it('checks the password state before role authorization', () => {
    expect(
      decideProtectedRoute({
        user: { ...user, role: 'sales', must_change_password: true },
        loading: false,
        logoutStatus: 'idle',
        allowedRoles: ['admin'],
      }),
    ).toEqual({ type: 'redirect', to: PASSWORD_CHANGE_REQUIRED_PATH })
  })

  it('preserves loading and logout safety states ahead of auth redirects', () => {
    expect(
      decideProtectedRoute({
        user: { ...user, must_change_password: true },
        loading: false,
        logoutStatus: 'pending',
      }),
    ).toEqual({ type: 'logout-pending' })

    expect(
      decideProtectedRoute({
        user: { ...user, must_change_password: true },
        loading: false,
        logoutStatus: 'blocked',
      }),
    ).toEqual({ type: 'logout-blocked' })
  })
})
