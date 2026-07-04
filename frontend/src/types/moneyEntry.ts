export type MoneyDirection = 'income' | 'expense'

export interface MoneyEntryVehicleRef {
  id: number
  stock_no: string
  brand: string
  model: string
}

export interface MoneyEntryCashAccountRef {
  id: number
  name: string
  type: string
}

export interface MoneyEntry {
  id: number
  entry_date: string
  direction: MoneyDirection
  category: string
  amount: number
  vehicle_id: number | null
  cash_account_id: number
  counterparty_name: string | null
  description: string | null
  vehicle: MoneyEntryVehicleRef | null
  cash_account: MoneyEntryCashAccountRef | null
  created_at: string | null
  updated_at: string | null
}

export interface MoneyEntryListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface MoneyEntryListResponse {
  data: MoneyEntry[]
  meta: MoneyEntryListMeta
}

export interface MoneyEntryListParams {
  vehicle_id?: number
  cash_account_id?: number
  direction?: MoneyDirection
  category?: string
  date_from?: string
  date_to?: string
  search?: string
  page?: number
}

export interface CreateMoneyEntryPayload {
  entry_date: string
  direction: MoneyDirection
  category: string
  amount: number
  cash_account_id: number
  vehicle_id?: number
  counterparty_name?: string
  description?: string
}
