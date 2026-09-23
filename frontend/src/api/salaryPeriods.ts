import { apiClient } from './client'
import type { SalaryPeriod, SalaryPeriodListItem } from '../types/salary'

export async function listSalaryPeriods(): Promise<SalaryPeriodListItem[]> {
  const { data } = await apiClient.get<{ data: SalaryPeriodListItem[] }>('/api/salary-periods')
  return data.data
}
export async function createSalaryPeriod(periodMonth: string): Promise<SalaryPeriod> {
  const { data } = await apiClient.post<{ data: SalaryPeriod }>('/api/salary-periods', { period_month: periodMonth })
  return data.data
}
export async function getSalaryPeriod(id: number): Promise<SalaryPeriod> {
  const { data } = await apiClient.get<{ data: SalaryPeriod }>(`/api/salary-periods/${id}`)
  return data.data
}
export async function recalculateSalaryPeriod(id: number): Promise<SalaryPeriod> {
  const { data } = await apiClient.post<{ data: SalaryPeriod }>(`/api/salary-periods/${id}/recalculate`)
  return data.data
}
export async function addSalaryAdjustment(id: number, payload: { user_id: number; type: 'manual_addition' | 'manual_deduction'; amount: number; description: string }): Promise<void> {
  await apiClient.post(`/api/salary-periods/${id}/adjustments`, payload)
}
export async function deleteSalaryAdjustment(periodId: number, itemId: number): Promise<void> {
  await apiClient.delete(`/api/salary-periods/${periodId}/adjustments/${itemId}`)
}
export async function confirmSalaryPeriod(id: number): Promise<SalaryPeriod> {
  const { data } = await apiClient.post<{ data: SalaryPeriod }>(`/api/salary-periods/${id}/confirm`)
  return data.data
}
export async function paySalaryPeriod(id: number, payload: { cash_account_id: number; payment_date: string; idempotency_key: string }): Promise<SalaryPeriod> {
  const { data } = await apiClient.post<{ data: SalaryPeriod }>(`/api/salary-periods/${id}/pay`, payload)
  return data.data
}
