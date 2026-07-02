export type VehicleStatus = 'preparing' | 'listed' | 'reserved' | 'sold' | 'cancelled'

export interface Vehicle {
  id: number
  stock_no: string
  status: VehicleStatus
  brand: string
  model: string
  year: number | null
  license_plate: string | null
  vin: string | null
  mileage_km: number | null
  color: string | null
  purchase_date: string | null
  purchase_source_type: string | null
  seller_name: string | null
  seller_phone: string | null
  purchase_price: number | null
  asking_price: number | null
  floor_price: number | null
  listing_date: string | null
  sales_note: string | null
  reserved_at: string | null
  sold_at: string | null
  sold_price: number | null
  buyer_name: string | null
  buyer_phone: string | null
  notes: string | null
  created_at: string | null
  updated_at: string | null
}

export interface VehicleListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface VehicleListResponse {
  data: Vehicle[]
  meta: VehicleListMeta
}

export interface VehicleListParams {
  search?: string
  status?: VehicleStatus
  page?: number
}

export interface VehicleFinancialSummary {
  income_total: number
  expense_total: number
  gross_profit: number
}

export interface VehicleMoneyEntry {
  id: number
  entry_date: string
  direction: 'income' | 'expense'
  category: string
  amount: number
  counterparty_name: string | null
  description: string | null
  cash_account: { id: number; name: string; type: string } | null
}

export interface VehicleDetailResponse {
  vehicle: Vehicle
  summary: VehicleFinancialSummary
  money_entries: VehicleMoneyEntry[]
}

export interface CreateVehiclePayload {
  brand: string
  model: string
  year?: number
  license_plate?: string
  vin?: string
  mileage_km?: number
  color?: string
  purchase_date?: string
  purchase_source_type?: string
  seller_name?: string
  seller_phone?: string
  purchase_price?: number
  asking_price?: number
  floor_price?: number
  sales_note?: string
  notes?: string
}
