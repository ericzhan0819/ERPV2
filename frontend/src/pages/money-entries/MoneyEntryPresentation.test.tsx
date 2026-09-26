// @vitest-environment jsdom

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as cashAccountsApi from '../../api/cashAccounts'
import * as moneyEntriesApi from '../../api/moneyEntries'
import * as vehiclesApi from '../../api/vehicles'
import { useAuth } from '../../hooks/useAuth'
import type { MoneyEntry } from '../../types/moneyEntry'
import type { User, UserRole } from '../../types/user'
import { MoneyEntryCreate } from './MoneyEntryCreate'
import { MoneyEntryList } from './MoneyEntryList'

vi.mock('../../api/cashAccounts', () => ({
  listCashAccountOptions: vi.fn(),
}))

vi.mock('../../api/moneyEntries', () => ({
  approveMoneyEntry: vi.fn(),
  createMoneyEntry: vi.fn(),
  listMoneyEntries: vi.fn(),
  rejectMoneyEntry: vi.fn(),
}))

vi.mock('../../api/vehicles', () => ({
  listVehicleOptions: vi.fn(),
}))

vi.mock('../../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

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

function setRole(role: UserRole) {
  vi.mocked(useAuth).mockReturnValue({
    user: userWithRole(role),
  } as ReturnType<typeof useAuth>)
}

const pendingEntry: MoneyEntry = {
  id: 9,
  review_token: 'a'.repeat(64),
  entry_date: '2026-07-29',
  direction: 'expense',
  category: '一般支出',
  amount: 3000,
  vehicle_id: null,
  cash_account_id: 1,
  counterparty_name: '供應商',
  description: '耗材',
  approval_status: 'pending',
  approved_by: null,
  approved_at: null,
  vehicle: null,
  cash_account: { id: 1, name: '營運現金', type: 'cash' },
  created_at: '2026-07-29T10:00:00+08:00',
  updated_at: '2026-07-29T10:00:00+08:00',
}

function renderList(path = '/money-entries') {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/money-entries" element={<MoneyEntryList />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('Money entry presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setRole('admin')
    vi.mocked(cashAccountsApi.listCashAccountOptions).mockResolvedValue([
      { id: 1, name: '營運現金', type: 'cash', is_active: true },
    ])
    vi.mocked(vehiclesApi.listVehicleOptions).mockResolvedValue([])
  })

  it('keeps the vehicle-binding rule concise and exposes validation beside each required field', async () => {
    const interaction = userEvent.setup()
    render(
      <MemoryRouter>
        <MoneyEntryCreate />
      </MemoryRouter>,
    )

    expect(screen.getByText('一般營運收支不綁車；單車收支必須選擇關聯車輛。')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('日期'), { target: { value: '' } })
    fireEvent.submit(screen.getByRole('button', { name: '建立收支' }).closest('form')!)

    const expectedErrors = [
      ['日期', '請選擇日期', 'money-entry-date-error'],
      ['分類', '請選擇分類', 'money-entry-category-error'],
      ['金額', '金額必須大於 0', 'money-entry-amount-error'],
      ['資金帳戶', '請選擇資金帳戶', 'money-entry-cash-account-error'],
    ] as const

    for (const [label, message, errorId] of expectedErrors) {
      const field = screen.getByLabelText(label)
      expect(field.getAttribute('aria-describedby')).toBe(errorId)
      expect(field.getAttribute('aria-invalid')).toBe('true')
      expect(document.getElementById(errorId)?.textContent).toBe(message)
    }
    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByLabelText('日期'))
    })
    expect(moneyEntriesApi.createMoneyEntry).not.toHaveBeenCalled()

    const amount = screen.getByLabelText('金額') as HTMLInputElement
    await interaction.type(amount, '1000')
    expect(amount.value).toBe('1000')
    expect(document.activeElement).toBe(amount)
  })

  it.each(['admin', 'manager', 'sales'] as const)('limits the purchase-payment category for %s', async (role) => {
    setRole(role)
    render(<MemoryRouter initialEntries={['/money-entries/create?direction=expense']}><MoneyEntryCreate /></MemoryRouter>)
    await screen.findByRole('option', { name: '營運現金' })
    expect(Boolean(screen.queryByRole('option', { name: '購車付款' }))).toBe(role !== 'sales')
    expect(screen.getByRole('option', { name: '維修支出' })).toBeTruthy()
  })

  it('shows an oversized amount beside the field in Chinese without submitting', async () => {
    render(<MemoryRouter><MoneyEntryCreate /></MemoryRouter>)
    await screen.findByRole('option', { name: '營運現金' })
    fireEvent.change(screen.getByLabelText('金額'), { target: { value: '1000000000000' } })
    fireEvent.change(screen.getByLabelText('分類'), { target: { value: '一般收入' } })
    fireEvent.change(screen.getByLabelText('資金帳戶'), { target: { value: '1' } })
    fireEvent.submit(screen.getByRole('button', { name: '建立收支' }).closest('form')!)
    expect(document.getElementById('money-entry-amount-error')?.textContent).toBe('金額不得超過 999,999,999,999 元')
    expect(screen.getByLabelText('金額').getAttribute('aria-describedby')).toBe('money-entry-amount-error')
    expect(moneyEntriesApi.createMoneyEntry).not.toHaveBeenCalled()
  })

  it('associates server amount errors with the amount field', async () => {
    vi.mocked(moneyEntriesApi.createMoneyEntry).mockRejectedValueOnce({
      isAxiosError: true, response: { status: 422, data: { errors: { amount: ['金額驗證失敗'] } } },
    })
    render(<MemoryRouter><MoneyEntryCreate /></MemoryRouter>)
    await screen.findByRole('option', { name: '營運現金' })
    fireEvent.change(screen.getByLabelText('金額'), { target: { value: '1000' } })
    fireEvent.change(screen.getByLabelText('分類'), { target: { value: '一般收入' } })
    fireEvent.change(screen.getByLabelText('資金帳戶'), { target: { value: '1' } })
    fireEvent.submit(screen.getByRole('button', { name: '建立收支' }).closest('form')!)
    expect(await screen.findByText('金額驗證失敗')).toBeTruthy()
    expect(screen.getByLabelText('金額').getAttribute('aria-describedby')).toBe('money-entry-amount-error')
  })

  it('keeps field validation before exposing and focusing a general API error', async () => {
    const interaction = userEvent.setup()
    vi.mocked(moneyEntriesApi.createMoneyEntry).mockRejectedValue(new Error('network'))
    render(
      <MemoryRouter>
        <MoneyEntryCreate />
      </MemoryRouter>,
    )

    await screen.findByRole('option', { name: '營運現金' })
    await interaction.type(screen.getByLabelText('金額'), '1000')
    await interaction.selectOptions(screen.getByLabelText('資金帳戶'), '1')
    await interaction.click(screen.getByRole('button', { name: '建立收支' }))
    expect(document.getElementById('money-entry-category-error')?.textContent).toBe('請選擇分類')
    expect(moneyEntriesApi.createMoneyEntry).not.toHaveBeenCalled()

    await interaction.selectOptions(screen.getByLabelText('分類'), '一般收入')
    await interaction.click(screen.getByRole('button', { name: '建立收支' }))

    const error = await screen.findByRole('alert')
    expect(error.textContent).toBe('新增收支失敗，請稍後再試')
    const firstField = screen.getByLabelText('日期')
    expect(error.compareDocumentPosition(firstField) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(document.getElementById('money-entry-category-error')).toBeNull()
    expect(document.activeElement).toBe(error)
  })

  it('preserves active filters and distinguishes filtered no-result from empty data', async () => {
    vi.mocked(moneyEntriesApi.listMoneyEntries).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })

    renderList('/money-entries?approval=pending')

    expect(await screen.findByText('尚無符合條件的收支紀錄')).toBeTruthy()
    expect(screen.getByLabelText('已套用篩選條件').textContent).toContain('審核：待審核')
    expect(screen.getAllByRole('button', { name: '清除篩選條件' })).toHaveLength(2)
    expect(screen.queryByText('尚無收支紀錄')).toBeNull()
  })

  it('keeps approval status and admin approve/reject outcomes', async () => {
    const interaction = userEvent.setup()
    vi.mocked(moneyEntriesApi.listMoneyEntries)
      .mockResolvedValueOnce({
        data: [pendingEntry],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
      })
      .mockRejectedValueOnce(new Error('offline'))
    vi.mocked(moneyEntriesApi.approveMoneyEntry).mockResolvedValue({
      ...pendingEntry,
      approval_status: 'approved',
    })

    renderList()

    const row = (await screen.findByText('耗材')).closest('tr')
    expect(row).not.toBeNull()
    expect(within(row!).getByText('待審核')).toBeTruthy()
    expect(within(row!).getByText('營運現金')).toBeTruthy()
    await interaction.click(within(row!).getByRole('button', { name: '核准' }))
    expect(moneyEntriesApi.approveMoneyEntry).toHaveBeenCalledWith(9, pendingEntry.review_token)
    expect((await screen.findByRole('alert')).textContent).toBe(
      '審核已送出，但列表可能不是最新；請重新整理後確認。',
    )
    expect(within(row!).getByText('待審核')).toBeTruthy()
  })

  it.each([
    ['approve', 409, '收支內容已變更，請重新確認後再審核'],
    ['approve', 422, '已結案車輛的收款不可核准'],
    ['reject', 409, '收支內容已變更，請重新確認後再審核'],
    ['reject', 422, '只有待審核的收支可以駁回，狀態不可逆'],
  ] as const)('shows %s %s errors and refreshes without losing the reason', async (action, status, message) => {
    const api = action === 'approve' ? moneyEntriesApi.approveMoneyEntry : moneyEntriesApi.rejectMoneyEntry
    vi.mocked(api).mockRejectedValueOnce({
      isAxiosError: true,
      response: { status, data: status === 422 ? { message: 'Validation error', errors: { approval_status: [message] } } : { message } },
    })
    vi.mocked(moneyEntriesApi.listMoneyEntries)
      .mockResolvedValueOnce({ data: [pendingEntry], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } })
      .mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } })
    renderList('/money-entries?approval=pending')
    await userEvent.setup().click(await screen.findByRole('button', { name: action === 'approve' ? '核准' : '駁回' }))
    await waitFor(() => expect(moneyEntriesApi.listMoneyEntries).toHaveBeenCalledTimes(2))
    expect(await screen.findByText('尚無符合條件的收支紀錄')).toBeTruthy()
    expect(screen.getByRole('alert').textContent).toBe(message)
    expect(api).toHaveBeenCalledWith(pendingEntry.id, pendingEntry.review_token)
  })

  it('retains the review reason if reloading the list also fails', async () => {
    vi.mocked(moneyEntriesApi.approveMoneyEntry).mockRejectedValueOnce({
      isAxiosError: true,
      response: { status: 409, data: { message: '收支內容已變更，請重新確認後再審核' } },
    })
    vi.mocked(moneyEntriesApi.listMoneyEntries)
      .mockResolvedValueOnce({ data: [pendingEntry], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } })
      .mockRejectedValueOnce(new Error('offline'))
    renderList()
    await userEvent.setup().click(await screen.findByRole('button', { name: '核准' }))
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('收支列表載入失敗'))
    expect(screen.getByRole('alert').textContent).toContain('收支內容已變更，請重新確認後再審核')
  })

  it.each(['admin', 'manager', 'sales'] as const)('limits account filtering for %s, including bookmarked URLs and mobile filters', async (role) => {
    setRole(role)
    vi.mocked(moneyEntriesApi.listMoneyEntries).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    })
    renderList('/money-entries?cash_account_id=1')
    await waitFor(() => expect(moneyEntriesApi.listMoneyEntries).toHaveBeenCalled())
    expect(moneyEntriesApi.listMoneyEntries).toHaveBeenLastCalledWith(expect.objectContaining({
      cash_account_id: role === 'sales' ? undefined : 1,
    }))
    if (role === 'sales') {
      expect(screen.queryByLabelText('資金帳戶')).toBeNull()
      expect(screen.queryByText('資金帳戶：營運現金')).toBeNull()
      await userEvent.setup().click(screen.getByRole('button', { name: /篩選/ }))
      expect(screen.queryByLabelText('資金帳戶')).toBeNull()
    } else {
      expect(screen.getByLabelText('資金帳戶')).toBeTruthy()
    }
  })

  it('keeps sales-safe amounts while masking cash accounts and approval controls', async () => {
    setRole('sales')
    vi.mocked(moneyEntriesApi.listMoneyEntries).mockResolvedValue({
      data: [{ ...pendingEntry, cash_account: undefined }],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    })

    renderList()

    const row = (await screen.findByText('耗材')).closest('tr')
    expect(row).not.toBeNull()
    expect(within(row!).getByText(/3,000/)).toBeTruthy()
    expect(screen.queryByRole('columnheader', { name: '資金帳戶' })).toBeNull()
    expect(within(row!).queryByRole('button', { name: '核准' })).toBeNull()
    expect(within(row!).getByText('待審核')).toBeTruthy()
  })
})
