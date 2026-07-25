import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient, ensureCsrfCookie } from './client'
import {
  login,
  updateCurrentUserPassword,
  updateCurrentUserProfile,
} from './auth'
import type { User } from '../types/user'

vi.mock('./client', () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
  },
  ensureCsrfCookie: vi.fn(),
}))

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

describe('auth API contract', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('sends login instead of the legacy email field', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: user } })

    await expect(login('owner', 'password')).resolves.toEqual(user)

    expect(ensureCsrfCookie).toHaveBeenCalledOnce()
    expect(apiClient.post).toHaveBeenCalledWith('/api/login', {
      login: 'owner',
      password: 'password',
    })
  })

  it('uses the dedicated self profile endpoint and preserves an explicit null username', async () => {
    const updatedUser = { ...user, username: null }
    vi.mocked(apiClient.patch).mockResolvedValue({ data: { data: updatedUser } })

    await expect(updateCurrentUserProfile({ name: '王小明', username: null })).resolves.toEqual(updatedUser)

    expect(apiClient.patch).toHaveBeenCalledWith('/api/me/profile', {
      name: '王小明',
      username: null,
    })
  })

  it('uses the dedicated self password endpoint with confirmation', async () => {
    vi.mocked(apiClient.patch).mockResolvedValue({ data: { data: user } })
    const payload = {
      current_password: 'old-password',
      password: 'new-password-123',
      password_confirmation: 'new-password-123',
    }

    await expect(updateCurrentUserPassword(payload)).resolves.toEqual(user)

    expect(apiClient.patch).toHaveBeenCalledWith('/api/me/password', payload)
  })
})
