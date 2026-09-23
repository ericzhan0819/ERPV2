import { describe, expect, it } from 'vitest'
import type { User } from '../types/user'
import {
  StaleCurrentUserResponseError,
  applyAuthSessionInvalidated,
  applyCurrentUserResponse,
  applyPasswordChangeRequired,
  requireAcceptedCurrentUserResponse,
} from './currentUserState'

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

describe('current user context update', () => {
  it('accepts a complete response for the same logged-in user', () => {
    const updatedUser = { ...user, name: '王大明' }

    expect(applyCurrentUserResponse(user, updatedUser, 'idle')).toEqual({
      accepted: true,
      user: updatedUser,
    })
  })

  it('rejects a response after logout has started', () => {
    const updatedUser = { ...user, name: '王大明' }

    expect(applyCurrentUserResponse(user, updatedUser, 'pending')).toEqual({
      accepted: false,
      user,
    })
  })

  it('rejects stale responses when there is no current user or the user id differs', () => {
    expect(applyCurrentUserResponse(null, user, 'idle')).toEqual({
      accepted: false,
      user: null,
    })

    expect(
      applyCurrentUserResponse(user, { ...user, id: 2 }, 'idle'),
    ).toEqual({
      accepted: false,
      user,
    })
  })

  it('applies a password-required notification only to an idle logged-in user', () => {
    expect(
      applyPasswordChangeRequired(user, 'idle', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: true,
      user: { ...user, must_change_password: true },
    })

    expect(
      applyPasswordChangeRequired(user, 'pending', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: false,
      user,
    })
    expect(
      applyPasswordChangeRequired(null, 'idle', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: false,
      user: null,
    })

    const requiredUser = { ...user, must_change_password: true }
    expect(
      applyPasswordChangeRequired(
        requiredUser,
        'idle',
        'request-v1',
        'request-v1',
      ),
    ).toEqual({
      accepted: false,
      user: requiredUser,
    })
  })

  it('rejects a password-required response from an older request generation', () => {
    expect(
      applyPasswordChangeRequired(user, 'idle', 'request-v1', 'request-v2'),
    ).toEqual({
      accepted: false,
      user,
    })
  })

  it('clears an idle user after 401 without overriding logout safety states', () => {
    expect(
      applyAuthSessionInvalidated(user, 'idle', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: true,
      user: null,
    })
    expect(
      applyAuthSessionInvalidated(user, 'pending', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: false,
      user,
    })
    expect(
      applyAuthSessionInvalidated(user, 'blocked', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: false,
      user,
    })
  })

  it('rejects a 401 from an older request generation after a newer login', () => {
    expect(
      applyAuthSessionInvalidated(
        user,
        'idle',
        'request-v1',
        'request-v2',
      ),
    ).toEqual({
      accepted: false,
      user,
    })
  })

  it('accepts a current 401 while initial user loading is still empty', () => {
    expect(
      applyAuthSessionInvalidated(null, 'idle', 'request-v1', 'request-v1'),
    ).toEqual({
      accepted: true,
      user: null,
    })
    expect(
      applyAuthSessionInvalidated(null, 'idle', null, null),
    ).toEqual({
      accepted: true,
      user: null,
    })
    expect(
      applyAuthSessionInvalidated(null, 'idle', null, 'request-v1'),
    ).toEqual({
      accepted: false,
      user: null,
    })
  })

  it('throws instead of reporting success when a response was rejected', () => {
    const rejected = applyCurrentUserResponse(user, { ...user, name: '王大明' }, 'blocked')

    expect(() => requireAcceptedCurrentUserResponse(rejected)).toThrow(
      StaleCurrentUserResponseError,
    )
  })
})
