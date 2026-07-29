// @vitest-environment jsdom

import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as cashAccountsApi from '../../api/cashAccounts'
import * as commissionPlansApi from '../../api/commissionPlans'
import * as salaryPeriodsApi from '../../api/salaryPeriods'
import * as salaryProfilesApi from '../../api/salaryProfiles'
import * as usersApi from '../../api/users'
import * as vehiclesApi from '../../api/vehicles'
import type { CommissionPlan, SalaryPeriod } from '../../types/salary'
import type { Vehicle } from '../../types/vehicle'
import { CommissionAttributionPending } from '../vehicles/CommissionAttributionPending'
import { CommissionPlans } from './CommissionPlans'
import { SalaryPeriodDetail } from './SalaryPeriodDetail'
import { SalaryPeriodList } from './SalaryPeriodList'
import { SalaryProfiles } from './SalaryProfiles'

vi.mock('../../api/cashAccounts', () => ({
  listCashAccounts: vi.fn(),
}))

vi.mock('../../api/commissionPlans', () => ({
  createCommissionPlan: vi.fn(),
  listCommissionPlans: vi.fn(),
}))

vi.mock('../../api/salaryPeriods', () => ({
  addSalaryAdjustment: vi.fn(),
  confirmSalaryPeriod: vi.fn(),
  createSalaryPeriod: vi.fn(),
  deleteSalaryAdjustment: vi.fn(),
  getSalaryPeriod: vi.fn(),
  listSalaryPeriods: vi.fn(),
  paySalaryPeriod: vi.fn(),
  recalculateSalaryPeriod: vi.fn(),
}))

vi.mock('../../api/salaryProfiles', () => ({
  listSalaryProfiles: vi.fn(),
  updateSalaryProfile: vi.fn(),
}))

vi.mock('../../api/users', () => ({
  listUsers: vi.fn(),
}))

vi.mock('../../api/vehicles', () => ({
  listCommissionAgentOptions: vi.fn(),
  listPendingCommissionAttribution: vi.fn(),
  updateCommissionAttribution: vi.fn(),
}))

const commissionPlan: CommissionPlan = {
  id: 1,
  name: '2026 標準方案',
  effective_from: '2026-01-01',
  company_reserve_bps: 4000,
  purchase_bonus_bps: 2000,
  is_active: true,
  is_used: true,
  tiers: [{ id: 1, min_sales_count: 1, sales_bonus_bps: 2000 }],
  created_at: null,
}

function salaryPeriod(
  status: SalaryPeriod['status'],
  periodMonth = '2000-01',
): SalaryPeriod {
  return {
    id: 1,
    period_month: periodMonth,
    status,
    commission_plan: commissionPlan,
    settlements: [],
    totals: {
      purchase_bonus_total: 0,
      sales_bonus_total: 0,
      manual_addition_total: 0,
      manual_deduction_total: 0,
      gross_pay: 30000,
      deduction_total: 0,
      net_pay: 30000,
      company_reserve_total: 10000,
      company_remaining_total: 5000,
      company_totals_available: true,
    },
    confirmed_at: status === 'draft' ? null : '2026-07-01T00:00:00Z',
    paid_at: status === 'paid' ? '2026-07-02T00:00:00Z' : null,
    payment_date: status === 'paid' ? '2026-07-02' : null,
    cash_account: status === 'paid' ? { id: 1, name: '營運帳戶', type: 'cash' } : null,
    anomalies: [],
    commission_warnings: [],
    has_blocking_issues: false,
  }
}

function renderSalaryDetail(period: SalaryPeriod) {
  vi.mocked(salaryPeriodsApi.getSalaryPeriod).mockResolvedValue(period)
  render(
    <MemoryRouter initialEntries={['/salary/periods/1']}>
      <Routes>
        <Route path="/salary/periods/:id" element={<SalaryPeriodDetail />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('salary copy presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(cashAccountsApi.listCashAccounts).mockResolvedValue([])
    vi.mocked(salaryProfilesApi.listSalaryProfiles).mockResolvedValue([])
    vi.mocked(usersApi.listUsers).mockResolvedValue([])
  })

  it('gives the salary-period empty state a next step', async () => {
    vi.mocked(salaryPeriodsApi.listSalaryPeriods).mockResolvedValue([])
    render(
      <MemoryRouter>
        <SalaryPeriodList />
      </MemoryRouter>,
    )

    expect(
      await screen.findAllByText('尚未建立薪資月份，請選擇月份並建立草稿。'),
    ).toHaveLength(2)
    expect(screen.getByRole('button', { name: '建立月份草稿' })).toBeTruthy()
  })

  it('shows the plan-version rule only on used plans', async () => {
    vi.mocked(commissionPlansApi.listCommissionPlans).mockResolvedValue([
      commissionPlan,
      {
        ...commissionPlan,
        id: 2,
        name: '尚未使用方案',
        is_used: false,
      },
    ])
    render(
      <MemoryRouter>
        <CommissionPlans />
      </MemoryRouter>,
    )

    expect(
      await screen.findAllByText('規則已鎖定；變更請建立新版本。'),
    ).toHaveLength(1)
    expect(screen.getByText('已使用・唯讀')).toBeTruthy()
    expect(screen.getByText('尚未使用')).toBeTruthy()
    expect(screen.queryByText(/已使用方案永久唯讀/)).toBeNull()
  })

  it('keeps the salary-profile snapshot boundary and actionable empty state', async () => {
    render(
      <MemoryRouter>
        <SalaryProfiles />
      </MemoryRouter>,
    )

    expect(
      screen.getByText('只影響未確認月份；歷史薪資快照不變。'),
    ).toBeTruthy()
    expect(await screen.findByText('目前沒有可設定的員工')).toBeTruthy()
  })

  it('shows the open-month warning only while a draft month is still open', async () => {
    renderSalaryDetail(salaryPeriod('draft', '2999-12'))

    expect(
      await screen.findByText(
        '2999-12 尚未結束；請於下個月確認結算，以免漏掉後續成交。',
      ),
    ).toBeTruthy()
    expect(
      (screen.getByRole('button', { name: '確認結算' }) as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('does not show the open-month warning for a closed draft month', async () => {
    renderSalaryDetail(salaryPeriod('draft'))

    await screen.findByRole('heading', { name: '2000-01 薪資' })
    expect(screen.queryByText(/尚未結束/)).toBeNull()
    expect(
      (screen.getByRole('button', { name: '確認結算' }) as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it.each([
    ['confirmed', '本月已確認，薪資快照與車輛歸屬已鎖定。'],
    ['paid', '已於 2026-07-02 由 營運帳戶 發薪；薪資快照與車輛歸屬已鎖定，本月份唯讀。'],
  ] as const)('keeps the %s lock outcome', async (status, message) => {
    renderSalaryDetail(salaryPeriod(status))

    expect(await screen.findByText(message)).toBeTruthy()
  })

  it('keeps blocking anomalies separate from non-blocking commission guidance', async () => {
    const period = salaryPeriod('draft')
    period.has_blocking_issues = true
    period.anomalies = [{
      vehicle_id: 10,
      stock_no: 'A001',
      code: 'missing_attribution',
      field: 'sales_agent_id',
      message: '缺少賣車人',
      correction: { label: '補獎金歸屬', action: 'commission_attribution' },
      context: {},
    }]
    period.commission_warnings = [{
      vehicle_id: 11,
      stock_no: 'A002',
      code: 'commission_disabled',
      role: 'sales',
      agent_id: 2,
      agent_name: '業務同仁',
      message: '未啟用獎金',
      correction: { label: '調整薪資設定', action: 'salary_profile' },
    }]
    renderSalaryDetail(period)

    expect(await screen.findByRole('heading', { name: '阻擋異常（1）' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: '獎金設定提示（不阻擋確認）' })).toBeTruthy()
    expect(screen.getByRole('link', { name: '補獎金歸屬' }).getAttribute('href')).toBe(
      '/vehicles/commission-attribution',
    )
    expect(screen.getByText(/金額將保留在公司剩餘分配額/)).toBeTruthy()
  })

  it('shows attribution cause and next step only while pending vehicles exist', async () => {
    const vehicle = {
      id: 10,
      stock_no: 'A001',
      brand: 'Toyota',
      model: 'Altis',
      sold_at: '2026-07-01T00:00:00Z',
      purchase_agent_id: null,
      sales_agent_id: null,
    } as unknown as Vehicle
    vi.mocked(vehiclesApi.listPendingCommissionAttribution)
      .mockResolvedValueOnce([vehicle])
      .mockResolvedValueOnce([])
    vi.mocked(vehiclesApi.listCommissionAgentOptions).mockResolvedValue([
      { id: 1, name: '收車同仁', role: 'sales' },
      { id: 2, name: '賣車同仁', role: 'sales' },
    ])
    vi.mocked(vehiclesApi.updateCommissionAttribution).mockResolvedValue(vehicle)
    const interaction = userEvent.setup()
    render(
      <MemoryRouter>
        <CommissionAttributionPending />
      </MemoryRouter>,
    )

    expect(
      await screen.findByText(
        '這些成交車輛缺少收車人或賣車人；補齊後請回薪資月份重算草稿。',
      ),
    ).toBeTruthy()
    const selects = screen.getAllByRole('combobox')
    await interaction.selectOptions(selects[0], '1')
    await interaction.selectOptions(selects[1], '2')
    await interaction.click(screen.getAllByRole('button', { name: '儲存歸屬' })[0])

    expect(vehiclesApi.updateCommissionAttribution).toHaveBeenCalledWith(10, {
      purchase_agent_id: 1,
      sales_agent_id: 2,
    })
    expect(
      await screen.findAllByText('目前沒有待補資料'),
    ).toHaveLength(2)
    expect(screen.queryByText(/這些成交車輛缺少/)).toBeNull()
  })
})
