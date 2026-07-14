import { apiClient } from './client'
import type { CommissionPlan, CommissionPlanPayload } from '../types/salary'

export async function listCommissionPlans(): Promise<CommissionPlan[]> {
  const { data } = await apiClient.get<{ data: CommissionPlan[] }>('/api/commission-plans')
  return data.data
}
export async function createCommissionPlan(payload: CommissionPlanPayload): Promise<CommissionPlan> {
  const { data } = await apiClient.post<{ data: CommissionPlan }>('/api/commission-plans', payload)
  return data.data
}
