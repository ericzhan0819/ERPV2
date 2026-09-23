import { apiClient } from './client'
import type { SalaryProfile, SalaryProfilePayload } from '../types/salary'

export async function listSalaryProfiles(): Promise<SalaryProfile[]> {
  const { data } = await apiClient.get<{ data: SalaryProfile[] }>('/api/salary-profiles')
  return data.data
}
export async function updateSalaryProfile(userId: number, payload: SalaryProfilePayload): Promise<SalaryProfile> {
  const { data } = await apiClient.put<{ data: SalaryProfile }>(`/api/salary-profiles/${userId}`, payload)
  return data.data
}
