export interface DashboardSummary {
  cash_balance: number
  bank_balance: number
  other_balance: number
  total_funds: number
  monthly_income: number
  monthly_expense: number
  monthly_net_flow: number
  vehicle_counts: {
    preparing: number
    listed: number
    reserved: number
    sold: number
    cancelled: number
  }
  monthly_sold_count: number
}
