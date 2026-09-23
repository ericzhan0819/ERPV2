import type { UserRole } from '../types/user'
import {
  canApproveMoneyEntries,
  canManageVehicles,
  canRunSalesFlow,
  canViewFinancials,
} from './permissions'

export type DashboardActionId =
  | 'create_vehicle'
  | 'report_money_entry'
  | 'create_customer'
  | 'report_preparation_expense'

export interface DashboardAction {
  id: DashboardActionId
  label: string
  to: string
}

const sharedActions: DashboardAction[] = [
  { id: 'report_money_entry', label: '回報收支', to: '/money-entries/create' },
  { id: 'create_customer', label: '建立客戶', to: '/customers/create' },
  { id: 'report_preparation_expense', label: '回報整備支出', to: '/vehicles?status=preparing' },
]

export function dashboardVisibility(role: UserRole | undefined) {
  return {
    canViewFinancials: canViewFinancials(role),
    canManageVehicles: canManageVehicles(role),
    canRunSalesFlow: canRunSalesFlow(role),
    canApproveMoneyEntries: canApproveMoneyEntries(role),
  }
}

export function dashboardActions(role: UserRole | undefined): DashboardAction[] {
  const visibility = dashboardVisibility(role)
  if (!visibility.canRunSalesFlow) return []

  return visibility.canManageVehicles
    ? [{ id: 'create_vehicle', label: '建立收車', to: '/vehicles/create' }, ...sharedActions]
    : [...sharedActions]
}

export function dashboardMoneyEntriesLink(direction: 'income' | 'expense', yearMonth: string): string {
  const [year, month] = yearMonth.split('-').map(Number)
  const finalDay = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const dateFrom = `${yearMonth}-01`
  const dateTo = `${yearMonth}-${String(finalDay).padStart(2, '0')}`

  return `/money-entries?direction=${direction}&date_from=${dateFrom}&date_to=${dateTo}&approval=approved`
}

export function dashboardSoldVehiclesLink(yearMonth: string): string {
  return `/vehicles?status=sold&sold_month=${yearMonth}`
}
