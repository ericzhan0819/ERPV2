// @vitest-environment jsdom

import { MemoryRouter } from 'react-router-dom'
import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as dashboardApi from '../api/dashboard'
import { useAuth } from '../hooks/useAuth'
import type { DashboardSummary } from '../types/dashboard'
import type { User, UserRole } from '../types/user'
import { Dashboard } from './Dashboard'

vi.mock('../api/dashboard', () => ({
  getDashboardSummary: vi.fn(),
}))

vi.mock('../hooks/useAuth', () => ({
  useAuth: vi.fn(),
}))

const summary: DashboardSummary = {
  work_overview: {
    preparation_pending_count: 1,
    listing_pending_count: 2,
    delivery_pending_count: 3,
    pending_money_entry_count: 4,
  },
  business_overview: {
    inventory_count: 5,
    sold_month: '2026-07',
    cash_balance: 600000,
    monthly_income: 700000,
    monthly_expense: 80000,
    monthly_gross_profit: 90000,
    monthly_sold_count: 6,
  },
  trends: {
    sales_count: [{ date: '2026-07-29', count: 1 }],
    gross_profit: [{ date: '2026-07-29', amount: 90000 }],
    cash_balance: [{ date: '2026-07-29', balance: 600000 }],
  },
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

function renderDashboard(role: UserRole) {
  vi.mocked(useAuth).mockReturnValue({
    user: userWithRole(role),
  } as ReturnType<typeof useAuth>)

  render(
    <MemoryRouter>
      <Dashboard />
    </MemoryRouter>,
  )
}

describe('Dashboard presentation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(dashboardApi.getDashboardSummary).mockResolvedValue(summary)
  })

  it('keeps work KPI labels, values, units, icons and filter links without repeated descriptions', async () => {
    renderDashboard('admin')

    const workSection = (await screen.findByRole('heading', { name: '工作概況' })).closest('section')
    expect(workSection).not.toBeNull()
    const work = within(workSection!)
    const expectedLinks = [
      ['待整備', '1 台', '/vehicles?status=preparing&is_preparation_completed=false'],
      ['待上架', '2 台', '/vehicles?status=preparing&is_preparation_completed=true'],
      ['待交車', '3 台', '/vehicles?status=reserved'],
      ['待審核收支', '4 筆', '/money-entries?approval=pending'],
    ] as const

    for (const [label, value, href] of expectedLinks) {
      const link = work.getByRole('link', { name: new RegExp(`${label} ${value}`) })
      expect(link.getAttribute('href')).toBe(href)
      expect(link.className).toContain('min-h-36')
      expect(link.querySelector('svg')).not.toBeNull()
    }

    expect(work.queryByText('點選卡片前往對應工作區處理')).toBeNull()
    expect(work.queryByText('整備尚未完成')).toBeNull()
    expect(work.queryByText('整備完成，等待上架')).toBeNull()
    expect(work.queryByText('已保留，等待完成交車')).toBeNull()
    expect(work.queryByText('等待核准或駁回')).toBeNull()
  })

  it('keeps the business date and approved-only contract once while preserving KPI links', async () => {
    renderDashboard('admin')

    const businessSection = (await screen.findByRole('heading', { name: '經營概況' })).closest('section')
    expect(businessSection).not.toBeNull()
    const business = within(businessSection!)

    expect(business.getAllByText(/僅計已核准收支/)).toHaveLength(1)
    expect(
      business.getByText(
        '本月涵蓋完整月份：收入／支出依收支日期，毛利／成交依成交日期；金額僅計已核准收支，現金為正式帳面餘額。',
      ),
    ).toBeTruthy()
    expect(business.getByText('含保留中')).toBeTruthy()
    expect(business.getByRole('link', { name: /本月收入/ }).getAttribute('href')).toBe(
      '/money-entries?direction=income&date_from=2026-07-01&date_to=2026-07-31&approval=approved',
    )
    expect(business.getByRole('link', { name: /本月毛利/ }).getAttribute('href')).toBe(
      '/vehicles?status=sold&sold_month=2026-07',
    )
    expect(business.queryByText(/完整當月/)).toBeNull()
  })

  it('keeps chart period, units and accessible descriptions', async () => {
    renderDashboard('admin')

    expect(await screen.findByText('近 30 天（含今天）')).toBeTruthy()
    expect(screen.getByRole('img', { name: /近 30 天成交量 單位：台/ })).toBeTruthy()
    expect(
      screen.getByRole('img', { name: /近 30 天毛利 依成交日歸屬，僅計已核准收支・單位：新台幣/ }),
    ).toBeTruthy()
    expect(
      screen.getByRole('img', { name: /現金變化 每日期末帳面餘額・單位：新台幣/ }),
    ).toBeTruthy()
  })

  it.each([
    ['admin', true, true],
    ['manager', true, false],
    ['sales', false, false],
  ] as const)('keeps %s financial and approval visibility', async (role, showsFinance, showsApproval) => {
    renderDashboard(role)
    await screen.findByRole('heading', { name: '工作概況' })

    expect(screen.queryByText('現金帳面餘額') !== null).toBe(showsFinance)
    expect(screen.queryByText('本月毛利') !== null).toBe(showsFinance)
    expect(screen.queryByText('待審核收支') !== null).toBe(showsApproval)
    expect(screen.queryByRole('img', { name: /近 30 天毛利/ }) !== null).toBe(showsFinance)
    expect(screen.queryByRole('img', { name: /現金變化/ }) !== null).toBe(showsFinance)
    expect(screen.queryByText(/收入／支出依收支日期/) !== null).toBe(showsFinance)
    expect(screen.getByText('含保留中')).toBeTruthy()
  })
})
