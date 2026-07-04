import { apiClient } from './client'
import type { CreateMoneyEntryPayload, MoneyEntry, MoneyEntryListParams, MoneyEntryListResponse } from '../types/moneyEntry'

export async function listMoneyEntries(params: MoneyEntryListParams): Promise<MoneyEntryListResponse> {
  const { data } = await apiClient.get<MoneyEntryListResponse>('/api/money-entries', { params })
  return data
}

export async function createMoneyEntry(payload: CreateMoneyEntryPayload): Promise<MoneyEntry> {
  const { data } = await apiClient.post<{ data: MoneyEntry }>('/api/money-entries', payload)
  return data.data
}
