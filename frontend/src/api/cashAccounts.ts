import { apiClient } from './client'
import type { CashAccountOption } from '../types/cashAccount'

export async function listCashAccounts(): Promise<CashAccountOption[]> {
  const { data } = await apiClient.get<{ data: CashAccountOption[] }>('/api/cash-accounts')
  return data.data
}
