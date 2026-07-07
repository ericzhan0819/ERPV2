import type { UserRole } from '../types/user'

// 白名單制：僅列出的角色可通過，任何未知或未來新增的角色一律視為不可通過，
// 對齊後端 User::canViewFinancials() / User::canViewSalesPricing() / VehiclePolicy 的判斷方式。
const FINANCIAL_ROLES: UserRole[] = ['admin', 'manager']
const SALES_PRICING_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const VEHICLE_MANAGE_ROLES: UserRole[] = ['admin', 'manager']
const SALES_FLOW_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const CUSTOMER_DELETE_ROLES: UserRole[] = ['admin']

// 收購價 / 成交價 / 毛利 / 資金餘額 / 完整收支金額等最敏感財務欄位。
export function canViewFinancials(role: UserRole | undefined): boolean {
  return !!role && FINANCIAL_ROLES.includes(role)
}

// 開價 / 底價：業務跟客人議價的依據，sales 也可看，但不含收購價 / 成交價 / 毛利。
export function canViewSalesPricing(role: UserRole | undefined): boolean {
  return !!role && SALES_PRICING_ROLES.includes(role)
}

export function canManageVehicles(role: UserRole | undefined): boolean {
  return !!role && VEHICLE_MANAGE_ROLES.includes(role)
}

export function canRunSalesFlow(role: UserRole | undefined): boolean {
  return !!role && SALES_FLOW_ROLES.includes(role)
}

export function canDeleteCustomer(role: UserRole | undefined): boolean {
  return !!role && CUSTOMER_DELETE_ROLES.includes(role)
}
