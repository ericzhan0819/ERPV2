import { describe, expect, it } from 'vitest'
import type { User } from '../types/user'
import { applyCurrentUserResponse } from './currentUserState'

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
})
