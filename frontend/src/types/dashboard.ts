export interface DashboardSummary {
  work_overview: {
    preparation_pending_count: number
    listing_pending_count: number
    delivery_pending_count: number
    pending_money_entry_count?: number
  }
  business_overview: {
    inventory_count: number
    cash_balance?: number
    monthly_income?: number
    monthly_expense?: number
    monthly_gross_profit?: number
    monthly_sold_count?: number
  }
  trends: {
    sales_count: DashboardCountPoint[]
    gross_profit?: DashboardAmountPoint[]
    cash_balance?: DashboardBalancePoint[]
  }
  // 第 4 部分切換新 Dashboard 畫面前的相容欄位。
  cash_balance?: number
  bank_balance?: number
  other_balance?: number
  total_funds?: number
  monthly_income?: number
  monthly_expense?: number
  monthly_net_flow?: number
  vehicle_counts: {
    preparing: number
    listed: number
    reserved: number
    sold: number
    cancelled: number
  }
  monthly_sold_count?: number
}

export interface DashboardCountPoint {
  date: string
  count: number
}

export interface DashboardAmountPoint {
  date: string
  amount: number
}

export interface DashboardBalancePoint {
  date: string
  balance: number
}
