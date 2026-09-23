// @vitest-environment jsdom

import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { StaleCurrentUserResponseError } from '../auth/currentUserState'
import { useAuth } from '../hooks/useAuth'
import { ThemeProvider } from '../hooks/useTheme'
import {
  PASSWORD_UPDATED_RELOGIN_NOTICE,
} from './authFormState'
import { PasswordChangeRequired } from './PasswordChangeRequired'

vi.mock('../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

const updatePassword = vi.fn()
const logout = vi.fn()

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

function renderPasswordChange() {
  vi.mocked(useAuth).mockReturnValue({
    updatePassword,
    logout,
  } as unknown as ReturnType<typeof useAuth>)

  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/change-password']}>
        <Routes>
          <Route path="/change-password" element={<PasswordChangeRequired />} />
          <Route path="/dashboard" element={<p>Dashboard 測試頁</p>} />
          <Route path="/login" element={<LoginTestPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  )
}

async function fillPasswordForm(
  confirmation = 'new-password',
) {
  const interaction = userEvent.setup()
  await interaction.type(screen.getByLabelText(/^目前密碼/), 'old-password')
  await interaction.type(screen.getByLabelText(/^新密碼/), 'new-password')
  await interaction.type(screen.getByLabelText(/^確認新密碼/), confirmation)
  return interaction
}

describe('PasswordChangeRequired', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('keeps the blocking outcome, visible labels and field-level password guidance', () => {
    renderPasswordChange()

    expect(screen.getByText('此帳號使用預設密碼。修改後才能進入營運功能。')).toBeTruthy()
    expect(screen.getByLabelText(/^目前密碼/)).toBeTruthy()
    const password = screen.getByLabelText(/^新密碼/)
    expect(screen.getByLabelText(/^確認新密碼/)).toBeTruthy()
    expect(screen.getByText('至少 8 個字元，且不可與目前密碼相同。')).toBeTruthy()
    expect(password.getAttribute('aria-describedby')).toBe('new-password-help')
    expect(document.getElementById('new-password-help')).not.toBeNull()
  })

  it('associates backend errors with the field without dropping its guidance', async () => {
    updatePassword.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          message: '資料驗證失敗',
          errors: { password: ['新密碼不可與目前密碼相同'] },
        },
      },
    })
    renderPasswordChange()
    const interaction = await fillPasswordForm()

    await interaction.click(screen.getByRole('button', { name: '修改密碼並繼續' }))

    expect(await screen.findByText('新密碼不可與目前密碼相同')).toBeTruthy()
    expect(screen.getByLabelText(/^新密碼/).getAttribute('aria-describedby')).toBe(
      'new-password-help new-password-error',
    )
  })

  it('keeps local confirmation errors on the confirmation field', async () => {
    renderPasswordChange()
    const interaction = await fillPasswordForm('different-password')

    await interaction.click(screen.getByRole('button', { name: '修改密碼並繼續' }))

    expect(updatePassword).not.toHaveBeenCalled()
    const confirmation = screen.getByLabelText(/^確認新密碼/)
    expect(await screen.findByText('新密碼與確認密碼不一致')).toBeTruthy()
    expect(confirmation.getAttribute('aria-describedby')).toBe(
      'password-confirmation-error',
    )
  })

  it('continues to the dashboard after a successful update', async () => {
    updatePassword.mockResolvedValue(undefined)
    renderPasswordChange()
    const interaction = await fillPasswordForm()

    await interaction.click(screen.getByRole('button', { name: '修改密碼並繼續' }))

    expect(updatePassword).toHaveBeenCalledWith({
      current_password: 'old-password',
      password: 'new-password',
      password_confirmation: 'new-password',
    })
    expect(await screen.findByText('Dashboard 測試頁')).toBeTruthy()
  })

  it('reports a committed stale response as a successful update that needs login', async () => {
    updatePassword.mockRejectedValue(new StaleCurrentUserResponseError())
    renderPasswordChange()
    const interaction = await fillPasswordForm()

    await interaction.click(screen.getByRole('button', { name: '修改密碼並繼續' }))

    expect(await screen.findByText('登入測試頁')).toBeTruthy()
    expect(screen.getByText(PASSWORD_UPDATED_RELOGIN_NOTICE)).toBeTruthy()
    expect(screen.queryByText('密碼修改失敗，請稍後再試')).toBeNull()
  })
})
