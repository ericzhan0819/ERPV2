import type { UserRole } from '../types/user'

// 白名單制：僅列出的角色可通過，任何未知或未來新增的角色一律視為不可通過，
// 對齊後端 User::canViewFinancials() / User::canViewSalesPricing() / VehiclePolicy 的判斷方式。
const FINANCIAL_ROLES: UserRole[] = ['admin', 'manager']
const SALES_PRICING_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const SALES_COLLECTION_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const VEHICLE_MANAGE_ROLES: UserRole[] = ['admin', 'manager']
const SALES_FLOW_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const CUSTOMER_DELETE_ROLES: UserRole[] = ['admin']
const APPROVE_MONEY_ENTRY_ROLES: UserRole[] = ['admin']

// 收購價 / 購車付款 / 完整成本 / 單車毛利 / 資金帳戶餘額 / 完整收支金額等最敏感財務欄位。
export function canViewFinancials(role: UserRole | undefined): boolean {
  return !!role && FINANCIAL_ROLES.includes(role)
}

// 開價 / 底價 / 成交價：業務議價與追蹤收款的依據，sales 也可看，但不含收購價 / 毛利。
export function canViewSalesPricing(role: UserRole | undefined): boolean {
  return !!role && SALES_PRICING_ROLES.includes(role)
}

// 訂金 / 尾款 / 退款等銷售收款安全金額。與 canViewSalesPricing 目前是同一組角色，
// 但語意不同，刻意分開命名，避免被誤用來開放成本、毛利、資金帳戶餘額等欄位。
export function canViewSalesCollectionAmounts(role: UserRole | undefined): boolean {
  return !!role && SALES_COLLECTION_ROLES.includes(role)
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

// 老闆身兼會計：只有 admin 可以核准 / 駁回收支，manager 即使可看完整營運金額也不行。
export function canApproveMoneyEntries(role: UserRole | undefined): boolean {
  return !!role && APPROVE_MONEY_ENTRY_ROLES.includes(role)
}
