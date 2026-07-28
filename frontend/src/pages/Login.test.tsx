// @vitest-environment jsdom

import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as authApi from '../api/auth'
import { PASSWORD_CHANGE_REQUIRED_PATH } from '../auth/passwordChangeRequired'
import { appConfig } from '../config/app'
import { AuthProvider } from '../hooks/useAuth'
import type { User } from '../types/user'
import { Login } from './Login'

vi.mock('../api/auth', () => ({
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

function renderLogin() {
  render(
    <AuthProvider>
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/dashboard" element={<p>Dashboard 測試頁</p>} />
          <Route
            path={PASSWORD_CHANGE_REQUIRED_PATH}
            element={<p>強制修改密碼測試頁</p>}
          />
        </Routes>
      </MemoryRouter>
    </AuthProvider>,
  )
}

describe('Login', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(authApi.me).mockRejectedValue(new Error('未登入'))
  })

  it.each([
    ['Email', 'owner@example.com'],
    ['username', 'owner'],
  ])('accepts a %s identifier and sends the login payload', async (_, identifier) => {
    vi.mocked(authApi.login).mockResolvedValue(baseUser)
    const user = userEvent.setup()

    renderLogin()

    expect(screen.getByText(appConfig.systemName)).toBeTruthy()
    expect(screen.getByText(appConfig.loginSubtitle)).toBeTruthy()
    const loginInput = screen.getByLabelText(/帳號名稱或 Email/)
    expect(loginInput.getAttribute('type')).toBe('text')
    expect(loginInput.getAttribute('name')).toBe('login')
    expect(loginInput.getAttribute('autocomplete')).toBe('username')

    await user.type(loginInput, identifier)
    await user.type(screen.getByLabelText(/密碼/), 'password')
    await user.click(screen.getByRole('button', { name: '登入' }))

    expect(authApi.login).toHaveBeenCalledWith(identifier, 'password')
    expect(await screen.findByText('Dashboard 測試頁')).toBeTruthy()
  })

  it('redirects a flagged user to the required password route', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      ...baseUser,
      must_change_password: true,
    })
    const user = userEvent.setup()

    renderLogin()
    await user.type(screen.getByLabelText(/帳號名稱或 Email/), 'owner')
    await user.type(screen.getByLabelText(/密碼/), 'password')
    await user.click(screen.getByRole('button', { name: '登入' }))

    expect(await screen.findByText('強制修改密碼測試頁')).toBeTruthy()
  })
})
