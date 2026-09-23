// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as usersApi from '../../api/users'
import { useAuth } from '../../hooks/useAuth'
import type { User } from '../../types/user'
import { UserList } from './UserList'

vi.mock('../../api/users', () => ({
  createUser: vi.fn(),
  deleteUser: vi.fn(),
  listUsers: vi.fn(),
  resetUserPassword: vi.fn(),
  setUserActive: vi.fn(),
  setUserRole: vi.fn(),
  updateUser: vi.fn(),
}))

vi.mock('../../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

const currentUser: User = {
  id: 1,
  name: '管理員本人',
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

const employee: User = {
  ...currentUser,
  id: 2,
  name: '業務同仁',
  email: 'sales@example.com',
  username: null,
  must_change_password: true,
  role: 'sales',
  is_admin: false,
}

function renderUserList() {
  vi.mocked(useAuth).mockReturnValue({
    user: currentUser,
  } as unknown as ReturnType<typeof useAuth>)
  render(<UserList />)
}

describe('UserList presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(usersApi.listUsers).mockResolvedValue([currentUser, employee])
  })

  it('keeps self restrictions visible and associates every restricted control', async () => {
    renderUserList()

    const selfRow = (await screen.findByText('管理員本人')).closest('tr')
    expect(selfRow).not.toBeNull()
    const row = within(selfRow!)
    const restriction = row.getByText(
      '不可變更自己的角色、停用或刪除自己的帳號；密碼請至「我的帳號」。',
    )
    expect(restriction.id).toBe('user-1-self-restrictions')

    const role = row.getByRole('combobox', { name: '管理員本人的角色：管理員' })
    expect((role as HTMLSelectElement).disabled).toBe(true)
    expect(role.getAttribute('title')).toBeNull()
    expect(role.getAttribute('aria-describedby')).toBe(restriction.id)

    for (const name of ['重設密碼', '停用', '刪除']) {
      const control = row.getByRole('button', { name })
      expect((control as HTMLButtonElement).disabled).toBe(true)
      expect(control.getAttribute('aria-describedby')).toBe(restriction.id)
    }
  })

  it('removes edit control-position teaching without changing the edit action', async () => {
    const interaction = userEvent.setup()
    renderUserList()

    const employeeRow = (await screen.findByText('業務同仁')).closest('tr')
    expect(employeeRow).not.toBeNull()
    await interaction.click(within(employeeRow!).getByRole('button', { name: '編輯' }))

    expect(screen.getByRole('button', { name: '儲存' })).toBeTruthy()
    expect(
      screen.queryByText(/角色請使用列表中的角色下拉選單/),
    ).toBeNull()
  })

  it('keeps the password-reset outcome and must-change-password badge', async () => {
    vi.mocked(usersApi.resetUserPassword).mockResolvedValue()
    const interaction = userEvent.setup()
    renderUserList()

    const employeeRow = (await screen.findByText('業務同仁')).closest('tr')
    expect(employeeRow).not.toBeNull()
    expect(within(employeeRow!).getByText('需修改密碼')).toBeTruthy()
    await interaction.click(
      within(employeeRow!).getByRole('button', { name: '重設密碼' }),
    )

    const resetLabel = screen.getByText('業務同仁 的新密碼')
    const resetInput = resetLabel.parentElement?.querySelector('input')
    expect(resetInput).not.toBeNull()
    await interaction.type(resetInput!, 'new-password')
    const resetForm = resetInput!.closest('form')
    expect(resetForm).not.toBeNull()
    await interaction.click(
      within(resetForm!).getByRole('button', { name: '重設密碼' }),
    )

    expect(usersApi.resetUserPassword).toHaveBeenCalledWith(2, 'new-password')
    expect(
      await screen.findByText(
        '密碼已重設；員工既有登入會失效，需以新密碼重新登入並修改密碼。',
      ),
    ).toBeTruthy()
  })
})
