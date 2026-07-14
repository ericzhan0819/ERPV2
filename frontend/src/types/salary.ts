export type SalaryPeriodStatus = 'draft' | 'confirmed' | 'paid'

export interface SalaryUserSummary { id: number; name: string; email?: string; role?: string }
export interface CommissionPlanTier { id?: number; min_sales_count: number; sales_bonus_bps: number; sort_order?: number }
export interface CommissionPlan {
  id: number; name: string; effective_from: string; company_reserve_bps: number
  purchase_bonus_bps: number; is_active: boolean; is_used: boolean | null
  tiers: CommissionPlanTier[]; created_at: string | null
}
export interface CommissionPlanPayload {
  name: string; effective_from: string; company_reserve_bps: number; purchase_bonus_bps: number
  is_active: boolean; tiers: Array<Pick<CommissionPlanTier, 'min_sales_count' | 'sales_bonus_bps'>>
}
export interface SalaryProfile {
  id: number; user_id: number; user: SalaryUserSummary & { is_active: boolean }
  base_salary: number; fixed_allowance: number; labor_insurance_deduction: number
  health_insurance_deduction: number; commission_enabled: boolean; is_active: boolean
}
export type SalaryProfilePayload = Pick<SalaryProfile, 'base_salary' | 'fixed_allowance' | 'labor_insurance_deduction' | 'health_insurance_deduction' | 'commission_enabled' | 'is_active'>
export interface SalarySettlementItem {
  id: number; type: string; vehicle_id: number | null
  vehicle: { id: number; stock_no: string; brand: string; model: string } | null
  amount: number; description: string; calculation: Record<string, number | string | null> | null
}
export interface SalarySettlement {
  id: number; user_id: number; user: SalaryUserSummary; eligible_sales_count: number
  sales_bonus_bps: number; base_salary: number; fixed_allowance: number
  labor_insurance_deduction: number; health_insurance_deduction: number
  purchase_bonus_total: number; sales_bonus_total: number; manual_addition_total: number
  manual_deduction_total: number; gross_pay: number; deduction_total: number; net_pay: number
  has_payment_entry: boolean; items: SalarySettlementItem[]
}
export interface SalaryTotals {
  purchase_bonus_total: number; sales_bonus_total: number; manual_addition_total: number
  manual_deduction_total: number; gross_pay: number; deduction_total: number; net_pay: number
  company_reserve_total: number; company_remaining_total: number
}
export interface SalaryAnomaly {
  vehicle_id: number; stock_no: string; code: string; field: string; message: string
  correction: { label: string; action: string }; context: Record<string, unknown>
}
export interface SalaryVehicleResult {
  vehicle_id: number; stock_no: string; brand: string; model: string; sold_at: string | null
  purchase_agent_id: number | null; sales_agent_id: number | null; eligible: boolean
  gross_profit: number; issues: SalaryAnomaly[]
}
export interface SalaryPeriodListItem {
  id: number; period_month: string; status: SalaryPeriodStatus
  commission_plan: { id: number; name: string }; settlement_count: number; net_pay_total: number
  confirmed_at: string | null; paid_at: string | null; payment_date: string | null; created_at: string | null
}
export interface SalaryPeriod {
  id: number; period_month: string; status: SalaryPeriodStatus; commission_plan: CommissionPlan
  settlements: SalarySettlement[]; totals: SalaryTotals
  confirmed_at: string | null; paid_at: string | null; payment_date: string | null
  cash_account: { id: number; name: string; type: string } | null
  anomalies?: SalaryAnomaly[]; vehicle_results?: SalaryVehicleResult[]; has_blocking_issues?: boolean
}
