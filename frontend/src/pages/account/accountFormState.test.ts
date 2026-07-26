import { describe, expect, it } from 'vitest'
import type { User } from '../../types/user'
import {
  accountProfileForm,
  accountProfilePayload,
  accountRoleLabel,
  passwordConfirmationError,
} from './accountFormState'

const user: User = {
  id: 1,
  name: '王小明',
  email: 'owner@example.com',
  username: null,
  must_change_password: false,
  role: 'admin',
  is_admin: true,
  is_active: true,
  phone: null,
  job_title: null,
  hire_date: null,
  notes: null,
}

describe('account form state', () => {
  it('represents a null username as an editable empty field', () => {
    expect(accountProfileForm(user)).toEqual({
      name: '王小明',
      username: '',
    })
  })

  it('normalizes profile input and sends an explicit null when username is cleared', () => {
    expect(
      accountProfilePayload({
        name: '  王大明  ',
        username: '  Sales.User-1  ',
      }),
    ).toEqual({
      name: '王大明',
      username: 'sales.user-1',
    })

    expect(
      accountProfilePayload({
        name: '王大明',
        username: '   ',
      }),
    ).toEqual({
      name: '王大明',
      username: null,
    })
  })

  it('uses the formal labels for all three roles', () => {
    expect(accountRoleLabel('admin')).toBe('管理員')
    expect(accountRoleLabel('manager')).toBe('經理')
    expect(accountRoleLabel('sales')).toBe('業務')
  })

  it('only reports a local confirmation mismatch', () => {
    expect(passwordConfirmationError('password-1', 'password-2')).toBe(
      '新密碼與確認密碼不一致',
    )
    expect(passwordConfirmationError('password-1', 'password-1')).toBeNull()
  })
})
