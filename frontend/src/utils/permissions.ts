import type { UserRole } from '../types/user'

// 白名單制：僅列出的角色可通過，任何未知或未來新增的角色一律視為不可通過，
// 對齊後端 User::canViewFinancials() / VehiclePolicy 的判斷方式。
const FINANCIAL_ROLES: UserRole[] = ['admin', 'manager']
const VEHICLE_MANAGE_ROLES: UserRole[] = ['admin', 'manager']
const SALES_FLOW_ROLES: UserRole[] = ['admin', 'manager', 'sales']
const CUSTOMER_DELETE_ROLES: UserRole[] = ['admin']

export function canViewFinancials(role: UserRole | undefined): boolean {
  return !!role && FINANCIAL_ROLES.includes(role)
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
