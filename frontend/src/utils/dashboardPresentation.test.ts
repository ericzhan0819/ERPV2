import { describe, expect, it } from 'vitest'
import {
  dashboardActions,
  dashboardMoneyEntriesLink,
  dashboardSoldVehiclesLink,
  dashboardVisibility,
} from './dashboardPresentation'

describe('dashboard role presentation contract', () => {
  it('gives admin all actions, financial data and approval work', () => {
    expect(dashboardActions('admin').map((action) => action.id)).toEqual([
      'create_vehicle',
      'report_money_entry',
      'create_customer',
      'report_preparation_expense',
    ])
    expect(dashboardVisibility('admin')).toEqual({
      canViewFinancials: true,
      canManageVehicles: true,
      canRunSalesFlow: true,
      canApproveMoneyEntries: true,
    })
  })

  it('removes approval from manager and vehicle creation plus finance from sales', () => {
    expect(dashboardVisibility('manager').canApproveMoneyEntries).toBe(false)
    expect(dashboardActions('sales').map((action) => action.id)).toEqual([
      'report_money_entry',
      'create_customer',
      'report_preparation_expense',
    ])
    expect(dashboardVisibility('sales').canViewFinancials).toBe(false)
    expect(dashboardVisibility('sales').canManageVehicles).toBe(false)
  })

  it('fails closed when there is no recognized authenticated role', () => {
    expect(dashboardActions(undefined)).toEqual([])
    expect(dashboardVisibility(undefined)).toEqual({
      canViewFinancials: false,
      canManageVehicles: false,
      canRunSalesFlow: false,
      canApproveMoneyEntries: false,
    })
  })
})

describe('dashboard KPI URL contract', () => {
  it('uses an inclusive calendar-month range and approved entries', () => {
    expect(dashboardMoneyEntriesLink('income', '2026-02')).toBe(
      '/money-entries?direction=income&date_from=2026-02-01&date_to=2026-02-28&approval=approved',
    )
  })

  it('uses the backend-provided sold month for sold vehicle links', () => {
    expect(dashboardSoldVehiclesLink('2026-07')).toBe('/vehicles?status=sold&sold_month=2026-07')
  })
})
