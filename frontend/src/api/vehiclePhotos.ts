import { apiClient } from './client'
import type { VehiclePhoto } from '../types/vehicle'

export async function listVehiclePhotos(vehicleId: number): Promise<VehiclePhoto[]> {
  const { data } = await apiClient.get<{ data: VehiclePhoto[] }>(`/api/vehicles/${vehicleId}/photos`)
  return data.data
}

export async function uploadVehiclePhotos(
  vehicleId: number,
  files: File[],
  idempotencyKey: string,
): Promise<VehiclePhoto[]> {
  const formData = new FormData()
  formData.append('idempotency_key', idempotencyKey)
  files.forEach((file) => formData.append('photos[]', file))
  const { data } = await apiClient.post<{ data: VehiclePhoto[] }>(`/api/vehicles/${vehicleId}/photos`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function reorderVehiclePhotos(vehicleId: number, photoIds: number[]): Promise<VehiclePhoto[]> {
  const { data } = await apiClient.patch<{ data: VehiclePhoto[] }>(`/api/vehicles/${vehicleId}/photos/reorder`, {
    photo_ids: photoIds,
  })
  return data.data
}

export async function setCoverVehiclePhoto(vehicleId: number, photoId: number): Promise<VehiclePhoto> {
  const { data } = await apiClient.patch<{ data: VehiclePhoto }>(`/api/vehicles/${vehicleId}/photos/${photoId}/cover`)
  return data.data
}

export async function deleteVehiclePhoto(vehicleId: number, photoId: number): Promise<void> {
  await apiClient.delete(`/api/vehicles/${vehicleId}/photos/${photoId}`)
}
