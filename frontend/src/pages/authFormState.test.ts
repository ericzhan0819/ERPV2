import { describe, expect, it } from 'vitest'
import type { User } from '../types/user'
import {
  apiErrorMessage,
  apiFieldErrors,
  loginSuccessPath,
} from './authFormState'

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

describe('auth form state', () => {
  it('routes login according to the password-change flag', () => {
    expect(loginSuccessPath(user)).toBe('/dashboard')
    expect(loginSuccessPath({ ...user, must_change_password: true })).toBe(
      '/change-password',
    )
  })

  it('extracts only allowed string validation errors', () => {
    const error = {
      isAxiosError: true,
      response: {
        data: {
          message: '驗證失敗',
          errors: {
            current_password: ['目前密碼不正確'],
            password: ['新密碼至少需要 8 個字元'],
            unrelated: ['不可顯示'],
          },
        },
      },
    }

    expect(
      apiFieldErrors(error, ['current_password', 'password'] as const),
    ).toEqual({
      current_password: '目前密碼不正確',
      password: '新密碼至少需要 8 個字元',
    })
    expect(apiErrorMessage(error, 'fallback')).toBe('驗證失敗')
  })

  it('uses a safe fallback for non-API failures', () => {
    expect(apiFieldErrors(new Error('network'), ['password'] as const)).toEqual({})
    expect(apiErrorMessage(new Error('network'), '請稍後再試')).toBe('請稍後再試')
  })
})
