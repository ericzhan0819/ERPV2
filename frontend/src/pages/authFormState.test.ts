import { describe, expect, it } from 'vitest'
import type { User } from '../types/user'
import {
  PASSWORD_UPDATED_RELOGIN_NOTICE,
  PROFILE_UPDATED_RELOGIN_NOTICE,
  apiErrorMessage,
  apiFieldErrors,
  isCommittedCurrentUserUpdateWithStaleContext,
  loginSuccessPath,
} from './authFormState'
import { StaleCurrentUserResponseError } from '../auth/currentUserState'

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

  it('distinguishes a committed current-user update from an API failure', () => {
    expect(
      isCommittedCurrentUserUpdateWithStaleContext(
        new StaleCurrentUserResponseError(),
      ),
    ).toBe(true)
    expect(
      isCommittedCurrentUserUpdateWithStaleContext(new Error('network')),
    ).toBe(false)
    expect(PASSWORD_UPDATED_RELOGIN_NOTICE).toBe(
      '密碼已更新，請使用新密碼重新登入',
    )
    expect(PROFILE_UPDATED_RELOGIN_NOTICE).toBe(
      '個人資料已更新，請重新登入確認',
    )
  })
})
