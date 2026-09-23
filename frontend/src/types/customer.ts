export type CustomerType = 'buyer' | 'seller' | 'both' | 'other'

export interface Customer {
  id: number
  name: string
  phone: string | null
  line_id: string | null
  customer_type: CustomerType
  source: string | null
  address: string | null
  notes: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CustomerPayload {
  name: string
  phone?: string | null
  line_id?: string | null
  customer_type: CustomerType
  source?: string | null
  address?: string | null
  notes?: string | null
}

export interface CustomerListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface CustomerListParams {
  search?: string
  customer_type?: CustomerType
  page?: number
  per_page?: number
}

export interface CustomerListResponse {
  data: Customer[]
  meta: CustomerListMeta
}

export interface CustomerRelatedVehicle {
  id: number
  stock_no: string
  status: string
  brand: string
  model: string
  sold_at: string | null
  sold_price?: number | null
}

export interface CustomerDetailResponse {
  customer: Customer
  vehicles_as_seller: CustomerRelatedVehicle[]
  vehicles_as_buyer: CustomerRelatedVehicle[]
}
