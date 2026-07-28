// @vitest-environment jsdom

import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../api/auth'
import { notifyPasswordChangeRequired } from '../auth/passwordChangeRequired'
import { appConfig } from '../config/app'
import { AuthProvider } from '../hooks/useAuth'
import { ThemeProvider } from '../hooks/useTheme'
import { ProtectedRoute } from '../routes/ProtectedRoute'
import type { User } from '../types/user'
import { AppLayout } from './AppLayout'

vi.mock('../api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
  updateCurrentUserProfile: vi.fn(),
  updateCurrentUserPassword: vi.fn(),
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

function renderLayout() {
  render(
    <ThemeProvider>
      <AuthProvider>
        <MemoryRouter initialEntries={['/dashboard']}>
          <Routes>
            <Route path="/login" element={<p>登入測試頁</p>} />
            <Route path="/change-password" element={<p>強制修改密碼測試頁</p>} />
            <Route
              element={
                <ProtectedRoute>
                  <AppLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/dashboard" element={<p>Dashboard 測試頁</p>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </ThemeProvider>,
  )
}

describe('AppLayout auth and presentation regression', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(authApi.me).mockResolvedValue(user)
  })

  it('uses config in the sidebar and keeps theme switching operational', async () => {
    const interaction = userEvent.setup()

    renderLayout()

    expect(await screen.findByText('Dashboard 測試頁')).toBeTruthy()
    expect(screen.getByText(appConfig.systemShortName)).toBeTruthy()
    await interaction.click(
      screen.getByRole('button', { name: '切換為深色模式' }),
    )

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(
      screen.getByRole('button', { name: '切換為淺色模式' }),
    ).toBeTruthy()
  })

  it('redirects an active session when the API reports the password-required code', async () => {
    renderLayout()
    expect(await screen.findByText('Dashboard 測試頁')).toBeTruthy()

    notifyPasswordChangeRequired()

    expect(await screen.findByText('強制修改密碼測試頁')).toBeTruthy()
  })

  it('logs out through the auth context and returns to login', async () => {
    vi.mocked(authApi.logout).mockResolvedValue()
    const interaction = userEvent.setup()

    renderLayout()
    await interaction.click(await screen.findByRole('button', { name: '登出' }))

    expect(authApi.logout).toHaveBeenCalledOnce()
    expect(await screen.findByText('登入測試頁')).toBeTruthy()
    expect(localStorage.getItem('erpv2:logout-state')).toBe('completed')
  })
})
