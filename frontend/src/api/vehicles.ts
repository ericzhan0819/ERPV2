import { apiClient } from './client'
import type {
  CloseSalePayload,
  CreateVehiclePayload,
  FinalPaymentPayload,
  FinalPaymentResponse,
  ListVehiclePayload,
  ReserveVehiclePayload,
  Vehicle,
  VehicleDetailResponse,
  VehicleListParams,
  VehicleListResponse,
  VehiclePrintClosingResponse,
  VehiclePrintIntakeResponse,
} from '../types/vehicle'

export async function listVehicles(params: VehicleListParams): Promise<VehicleListResponse> {
  const { data } = await apiClient.get<VehicleListResponse>('/api/vehicles', { params })
  return data
}

export async function getVehicle(id: number): Promise<VehicleDetailResponse> {
  const { data } = await apiClient.get<VehicleDetailResponse>(`/api/vehicles/${id}`)
  return data
}

export async function createVehicle(payload: CreateVehiclePayload): Promise<Vehicle> {
  const { data } = await apiClient.post<{ data: Vehicle }>('/api/vehicles', payload)
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

export async function getVehiclePrintIntake(id: number): Promise<VehiclePrintIntakeResponse> {
  const { data } = await apiClient.get<VehiclePrintIntakeResponse>(`/api/vehicles/${id}/print/intake`)
  return data
}

export async function getVehiclePrintClosing(id: number): Promise<VehiclePrintClosingResponse> {
  const { data } = await apiClient.get<VehiclePrintClosingResponse>(`/api/vehicles/${id}/print/closing`)
  return data
}
