import { apiClient } from './client'
import type {
  CloseSalePayload,
  CommissionAgent,
  CreateVehiclePayload,
  FinalPaymentPayload,
  FinalPaymentResponse,
  ListVehiclePayload,
  ReserveVehiclePayload,
  Vehicle,
  VehicleDetailResponse,
  VehicleExpensePayload,
  VehicleListParams,
  VehicleListResponse,
  VehiclePrintClosingResponse,
  VehiclePrintIntakeResponse,
  UpdateCommissionAttributionPayload,
  UpdateVehiclePublicDescriptionPayload,
  UpdateVehicleSalesPricingPayload,
} from '../types/vehicle'
import type { MoneyEntry } from '../types/moneyEntry'

export async function listVehicles(params: VehicleListParams): Promise<VehicleListResponse> {
  const { data } = await apiClient.get<VehicleListResponse>('/api/vehicles', { params })
  return data
}

export async function listVehicleOptions(): Promise<Vehicle[]> {
  const response = await listVehicles({ per_page: 100 })
  return response.data
}

export async function listCommissionAgentOptions(): Promise<CommissionAgent[]> {
  const { data } = await apiClient.get<{ data: CommissionAgent[] }>('/api/vehicles/commission-agent-options')
  return data.data
}

export async function listPendingCommissionAttribution(): Promise<Vehicle[]> {
  const { data } = await apiClient.get<{ data: Vehicle[] }>('/api/vehicles/commission-attribution-pending')
  return data.data
}

export async function updateCommissionAttribution(
  id: number,
  payload: UpdateCommissionAttributionPayload,
): Promise<Vehicle> {
  const { data } = await apiClient.patch<{ data: Vehicle }>(`/api/vehicles/${id}/commission-attribution`, payload)
  return data.data
}

export async function getVehicle(id: number): Promise<VehicleDetailResponse> {
  const { data } = await apiClient.get<VehicleDetailResponse>(`/api/vehicles/${id}`)
  return data
}

export async function createVehicle(payload: CreateVehiclePayload): Promise<Vehicle> {
  const { data } = await apiClient.post<{ data: Vehicle }>('/api/vehicles', payload)
  return data.data
}

async function getLatestVehicleIdentity(id: number) {
  // 局部編輯前重讀必要基本資料，避免把長時間開啟頁面的舊值寫回。
  const { vehicle } = await getVehicle(id)
  return {
    brand: vehicle.brand,
    model: vehicle.model,
    ...(vehicle.license_plate ? { license_plate: vehicle.license_plate } : {}),
    ...(vehicle.vin ? { vin: vehicle.vin } : {}),
  }
}

export async function updateVehiclePurchasePrice(vehicle: Vehicle, purchasePrice: number): Promise<Vehicle> {
  const identity = await getLatestVehicleIdentity(vehicle.id)
  const { data } = await apiClient.patch<{ data: Vehicle }>(`/api/vehicles/${vehicle.id}`, {
    ...identity,
    purchase_price: purchasePrice,
  })
  return data.data
}

export async function updateVehicleSalesPricing(
  vehicle: Vehicle,
  payload: UpdateVehicleSalesPricingPayload,
): Promise<Vehicle> {
  const identity = await getLatestVehicleIdentity(vehicle.id)
  const { data } = await apiClient.patch<{ data: Vehicle }>(`/api/vehicles/${vehicle.id}`, {
    ...identity,
    ...payload,
  })
  return data.data
}

export async function updateVehiclePublicDescription(
  vehicle: Vehicle,
  payload: UpdateVehiclePublicDescriptionPayload,
): Promise<Vehicle> {
  const identity = await getLatestVehicleIdentity(vehicle.id)
  const { data } = await apiClient.patch<{ data: Vehicle }>(`/api/vehicles/${vehicle.id}`, {
    ...identity,
    ...payload,
  })
  return data.data
}

export async function listVehicleForSale(id: number, payload: ListVehiclePayload): Promise<Vehicle> {
  const { data } = await apiClient.post<{ data: Vehicle }>(`/api/vehicles/${id}/list`, payload)
  return data.data
}

export async function reserveVehicle(id: number, payload: ReserveVehiclePayload): Promise<Vehicle> {
  const { data } = await apiClient.post<{ data: Vehicle }>(`/api/vehicles/${id}/reserve`, payload)
  return data.data
}

export async function recordFinalPayment(id: number, payload: FinalPaymentPayload): Promise<FinalPaymentResponse> {
  const { data } = await apiClient.post<FinalPaymentResponse>(`/api/vehicles/${id}/final-payment`, payload)
  return data
}

export async function closeSaleVehicle(id: number, payload: CloseSalePayload): Promise<Vehicle> {
  const { data } = await apiClient.post<{ data: Vehicle }>(`/api/vehicles/${id}/close-sale`, payload)
  return data.data
}

export async function recordVehicleExpense(id: number, payload: VehicleExpensePayload): Promise<MoneyEntry> {
  const { data } = await apiClient.post<{ data: MoneyEntry }>(`/api/vehicles/${id}/expense`, payload)
  return data.data
}

export async function getVehiclePrintIntake(id: number): Promise<VehiclePrintIntakeResponse> {
  const { data } = await apiClient.get<VehiclePrintIntakeResponse>(`/api/vehicles/${id}/print/intake`)
  return data
}

export async function getVehiclePrintClosing(id: number): Promise<VehiclePrintClosingResponse> {
  const { data } = await apiClient.get<VehiclePrintClosingResponse>(`/api/vehicles/${id}/print/closing`)
  return data
}
