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
