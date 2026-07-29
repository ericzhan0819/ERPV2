// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as cashAccountsApi from '../../api/cashAccounts'
import { useAuth } from '../../hooks/useAuth'
import type { CashAccountBalance } from '../../types/cashAccount'
import type { User, UserRole } from '../../types/user'
import { CashAccountList } from './CashAccountList'

vi.mock('../../api/cashAccounts', () => ({
  createCashAccount: vi.fn(),
  deleteCashAccount: vi.fn(),
  listCashAccountBalances: vi.fn(),
  setCashAccountActive: vi.fn(),
  updateCashAccount: vi.fn(),
}))

vi.mock('../../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

const account: CashAccountBalance = {
  id: 1,
  name: '營運現金',
  type: 'cash',
  opening_balance: 1000,
  current_balance: 1500,
  is_active: true,
}

function userWithRole(role: UserRole): User {
  return {
    id: 1,
    name: '測試使用者',
    email: 'user@example.com',
    username: 'user',
    must_change_password: false,
    role,
    is_admin: role === 'admin',
    is_active: true,
    phone: null,
    job_title: null,
    hire_date: null,
    notes: null,
  }
}

function renderCashAccounts(role: 'admin' | 'manager') {
  vi.mocked(useAuth).mockReturnValue({
    user: userWithRole(role),
  } as unknown as ReturnType<typeof useAuth>)
  render(<CashAccountList />)
}

describe('CashAccountList presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(cashAccountsApi.listCashAccountBalances).mockResolvedValue([account])
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('keeps formal balances and status visible without manager write controls', async () => {
    renderCashAccounts('manager')

    const row = (await screen.findByText('營運現金')).closest('tr')
    expect(row).not.toBeNull()
    const accountRow = within(row!)
    expect(accountRow.getByText('啟用中')).toBeTruthy()
    expect(accountRow.getByText(/1,000/)).toBeTruthy()
    expect(accountRow.getByText(/1,500/)).toBeTruthy()
    expect(accountRow.queryByRole('button')).toBeNull()
  })

  it('removes edit control-position teaching while preserving status actions', async () => {
    vi.mocked(cashAccountsApi.listCashAccountBalances)
      .mockResolvedValueOnce([account])
      .mockRejectedValueOnce(new Error('offline'))
    vi.mocked(cashAccountsApi.setCashAccountActive).mockResolvedValue({
      ...account,
      is_active: false,
    })
    const interaction = userEvent.setup()
    renderCashAccounts('admin')

    const row = (await screen.findByText('營運現金')).closest('tr')
    expect(row).not.toBeNull()
    await interaction.click(within(row!).getByRole('button', { name: '編輯' }))
    expect(screen.getByRole('button', { name: '儲存' })).toBeTruthy()
    expect(screen.queryByText(/啟用／停用請使用列表中的/)).toBeNull()

    await interaction.click(screen.getByRole('button', { name: '取消' }))
    const restoredRow = screen.getByText('營運現金').closest('tr')
    await interaction.click(within(restoredRow!).getByRole('button', { name: '停用' }))
    expect(cashAccountsApi.setCashAccountActive).toHaveBeenCalledWith(1, false)
    expect((await screen.findByRole('alert')).textContent).toBe(
      '操作已送出，但資金帳戶列表可能不是最新；請重新整理後確認。',
    )
    expect(screen.getByText('營運現金')).toBeTruthy()
  })

  it('keeps the irreversible delete confirmation', async () => {
    vi.mocked(cashAccountsApi.deleteCashAccount).mockResolvedValue()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const interaction = userEvent.setup()
    renderCashAccounts('admin')

    const row = (await screen.findByText('營運現金')).closest('tr')
    await interaction.click(within(row!).getByRole('button', { name: '刪除' }))

    expect(confirm).toHaveBeenCalledWith(
      '確定要刪除帳戶「營運現金」嗎？此操作無法復原。',
    )
    expect(cashAccountsApi.deleteCashAccount).toHaveBeenCalledWith(1)
  })
})
