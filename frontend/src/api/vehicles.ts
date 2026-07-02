import { apiClient } from './client'
import type {
  CreateVehiclePayload,
  Vehicle,
  VehicleDetailResponse,
  VehicleListParams,
  VehicleListResponse,
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
