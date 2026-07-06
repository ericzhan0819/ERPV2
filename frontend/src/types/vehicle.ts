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
  displacement: string | null
  transmission: string | null
  fuel_type: string | null
  parking_location: string | null
  has_registration_document: boolean
  has_spare_key: boolean
  is_transfer_completed: boolean
  is_inspection_completed: boolean
  is_preparation_completed: boolean
  lien_note: string | null
  condition_note: string | null
  purchase_date: string | null
  purchase_source_type: string | null
  seller_name: string | null
  seller_phone: string | null
  seller_customer_id: number | null
  purchase_price?: number | null
  asking_price?: number | null
  floor_price?: number | null
  listing_date: string | null
  sales_note: string | null
  reserved_at: string | null
  sold_at: string | null
  sold_price?: number | null
  buyer_name: string | null
  buyer_phone: string | null
  buyer_customer_id: number | null
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
  amount?: number
  counterparty_name: string | null
  description: string | null
  cash_account?: { id: number; name: string; type: string } | null
}

export interface VehicleDetailResponse {
  vehicle: Vehicle
  summary?: VehicleFinancialSummary
  money_entries: VehicleMoneyEntry[]
}

export interface ListVehiclePayload {
  asking_price: number
  floor_price?: number
  listing_date?: string
  sales_note?: string
}

export interface ReserveVehiclePayload {
  buyer_name: string
  buyer_phone?: string
  buyer_customer_id?: number
  sold_price: number
  deposit_amount: number
  cash_account_id: number
  entry_date?: string
  description?: string
  idempotency_key: string
}

export interface FinalPaymentPayload {
  amount: number
  cash_account_id: number
  idempotency_key: string
  entry_date?: string
  description?: string
}

export interface CloseSalePayload {
  sold_at?: string
}

export interface FinalPaymentResponse {
  vehicle: Vehicle
  warning: string | null
}

export interface VehiclePrintIntakeResponse {
  printed_at: string
  vehicle: Vehicle
}

export interface VehiclePrintClosingResponse {
  printed_at: string
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
  displacement?: string
  transmission?: string
  fuel_type?: string
  parking_location?: string
  has_registration_document?: boolean
  has_spare_key?: boolean
  is_transfer_completed?: boolean
  is_inspection_completed?: boolean
  is_preparation_completed?: boolean
  lien_note?: string
  condition_note?: string
  purchase_date?: string
  purchase_source_type?: string
  seller_name?: string
  seller_phone?: string
  seller_customer_id?: number
  purchase_price?: number
  asking_price?: number
  floor_price?: number
  sales_note?: string
  notes?: string
}
