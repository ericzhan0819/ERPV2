// @vitest-environment jsdom

import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../../api/auth'
import { StaleCurrentUserResponseError } from '../../auth/currentUserState'
import { AuthProvider } from '../../hooks/useAuth'
import { ThemeProvider } from '../../hooks/useTheme'
import { AppLayout } from '../../layouts/AppLayout'
import { ProtectedRoute } from '../../routes/ProtectedRoute'
import type { User } from '../../types/user'
import { PROFILE_UPDATED_RELOGIN_NOTICE } from '../authFormState'
import { Account } from './Account'

vi.mock('../../api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
  updateCurrentUserProfile: vi.fn(),
  updateCurrentUserPassword: vi.fn(),
}))

const baseUser: User = {
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

function validationError(errors: Record<string, string[]>) {
  return {
    isAxiosError: true,
    response: {
      status: 422,
      data: { message: '資料驗證失敗', errors },
    },
  }
}

function renderAccount(currentUser: User = baseUser) {
  vi.mocked(authApi.me).mockResolvedValue(currentUser)

  render(
    <ThemeProvider>
      <AuthProvider>
        <MemoryRouter initialEntries={['/account']}>
          <Routes>
            <Route path="/login" element={<LoginTestPage />} />
            <Route
              element={
                <ProtectedRoute>
                  <AppLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/account" element={<Account />} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </ThemeProvider>,
  )
}

function LoginTestPage() {
  const location = useLocation()
  const notice = (location.state as { notice?: string } | null)?.notice

  return (
    <>
      <p>登入測試頁</p>
      {notice && <p>{notice}</p>}
    </>
  )
}

describe('Account', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads the current user, keeps email and role readonly, and updates the header name', async () => {
    const updatedUser = {
      ...baseUser,
      name: '新顯示名稱',
      username: 'owner',
    }
    vi.mocked(authApi.updateCurrentUserProfile).mockResolvedValue(updatedUser)
    const interaction = userEvent.setup()

    renderAccount()

    expect(await screen.findByRole('heading', { name: '我的帳號' })).toBeTruthy()
    expect(screen.getByText(baseUser.email)).toBeTruthy()
    expect(screen.getByText('管理員')).toBeTruthy()
    expect(screen.queryByDisplayValue(baseUser.email)).toBeNull()

    const nameInput = screen.getByLabelText(/顯示名稱/)
    await interaction.clear(nameInput)
    await interaction.type(nameInput, updatedUser.name)
    await interaction.type(screen.getByLabelText('帳號名稱'), '  OwNeR  ')
    await interaction.click(
      screen.getByRole('button', { name: '儲存個人資料' }),
    )

    expect(authApi.updateCurrentUserProfile).toHaveBeenCalledWith({
      name: updatedUser.name,
      username: 'owner',
    })
    expect(
      await screen.findByLabelText(`我的帳號：${updatedUser.name}`),
    ).toBeTruthy()
    expect(screen.getByText('個人資料已更新')).toBeTruthy()
  })

  it.each([
    ['adds', null, 'sales.user', 'sales.user'],
    ['updates', 'old-name', 'new-name', 'new-name'],
    ['clears', 'old-name', '', null],
  ])(
    '%s a username with the normalized self-profile payload',
    async (_, initialUsername, input, expectedUsername) => {
      const currentUser = { ...baseUser, username: initialUsername }
      const updatedUser = { ...currentUser, username: expectedUsername }
      vi.mocked(authApi.updateCurrentUserProfile).mockResolvedValue(updatedUser)
      const interaction = userEvent.setup()

      renderAccount(currentUser)
      const usernameInput = await screen.findByLabelText('帳號名稱')
      await interaction.clear(usernameInput)
      if (input) await interaction.type(usernameInput, input)
      await interaction.click(
        screen.getByRole('button', { name: '儲存個人資料' }),
      )

      expect(authApi.updateCurrentUserProfile).toHaveBeenCalledWith({
        name: baseUser.name,
        username: expectedUsername,
      })
    },
  )

  it('shows password confirmation and backend field errors, then clears fields on success', async () => {
    const interaction = userEvent.setup()

    renderAccount()
    const currentPassword = await screen.findByLabelText(/目前密碼/)
    const password = screen.getByLabelText(/^新密碼/)
    const confirmation = screen.getByLabelText(/確認新密碼/)

    await interaction.type(currentPassword, 'old-password')
    await interaction.type(password, 'new-password')
    await interaction.type(confirmation, 'different-password')
    await interaction.click(screen.getByRole('button', { name: '更新密碼' }))

    expect(authApi.updateCurrentUserPassword).not.toHaveBeenCalled()
    expect(screen.getByText('新密碼與確認密碼不一致')).toBeTruthy()

    await interaction.clear(confirmation)
    await interaction.type(confirmation, 'new-password')
    vi.mocked(authApi.updateCurrentUserPassword).mockRejectedValueOnce(
      validationError({ current_password: ['目前密碼不正確'] }),
    )
    await interaction.click(screen.getByRole('button', { name: '更新密碼' }))

    expect(await screen.findByText('目前密碼不正確')).toBeTruthy()

    vi.mocked(authApi.updateCurrentUserPassword).mockResolvedValue({
      ...baseUser,
      must_change_password: false,
    })
    await interaction.click(screen.getByRole('button', { name: '更新密碼' }))

    expect(authApi.updateCurrentUserPassword).toHaveBeenLastCalledWith({
      current_password: 'old-password',
      password: 'new-password',
      password_confirmation: 'new-password',
    })
    expect(await screen.findByText('密碼已更新')).toBeTruthy()
    expect((currentPassword as HTMLInputElement).value).toBe('')
    expect((password as HTMLInputElement).value).toBe('')
    expect((confirmation as HTMLInputElement).value).toBe('')
  })

  it('places username validation errors on the username field', async () => {
    vi.mocked(authApi.updateCurrentUserProfile).mockRejectedValue(
      validationError({ username: ['帳號名稱已被使用'] }),
    )
    const interaction = userEvent.setup()

    renderAccount()
    const usernameInput = await screen.findByLabelText('帳號名稱')
    await interaction.type(usernameInput, 'duplicate')
    await interaction.click(
      screen.getByRole('button', { name: '儲存個人資料' }),
    )

    expect(await screen.findByText('帳號名稱已被使用')).toBeTruthy()
    expect(usernameInput.getAttribute('aria-invalid')).toBe('true')
  })

  it('does not report a committed profile update as failed when auth context changed', async () => {
    vi.mocked(authApi.updateCurrentUserProfile).mockRejectedValue(
      new StaleCurrentUserResponseError(),
    )
    const interaction = userEvent.setup()

    renderAccount()
    await screen.findByRole('heading', { name: '我的帳號' })
    await interaction.click(
      screen.getByRole('button', { name: '儲存個人資料' }),
    )

    expect(await screen.findByText('登入測試頁')).toBeTruthy()
    expect(screen.getByText(PROFILE_UPDATED_RELOGIN_NOTICE)).toBeTruthy()
    expect(
      screen.queryByText('個人資料更新失敗，請稍後再試'),
    ).toBeNull()
  })
})
