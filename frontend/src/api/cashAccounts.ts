import { apiClient } from './client'
import type {
  CashAccount,
  CashAccountBalance,
  CashAccountOption,
  CashAccountPayload,
  CashAccountUpdatePayload,
} from '../types/cashAccount'

export async function listCashAccounts(): Promise<CashAccountOption[]> {
  const { data } = await apiClient.get<{ data: CashAccountOption[] }>('/api/cash-accounts')
  return data.data
}

// 不含 opening_balance/current_balance 的最小欄位選單，sales 角色也可呼叫，
// 用於收訂金 / 收尾款 / 支出登記等表單選擇資金帳戶。
export async function listCashAccountOptions(): Promise<CashAccountOption[]> {
  const { data } = await apiClient.get<{ data: CashAccountOption[] }>('/api/cash-accounts/options')
  return data.data
}

export async function listCashAccountBalances(): Promise<CashAccountBalance[]> {
  const { data } = await apiClient.get<{ data: CashAccountBalance[] }>('/api/cash-accounts/balances')
  return data.data
}

export async function createCashAccount(payload: CashAccountPayload): Promise<CashAccount> {
  const { data } = await apiClient.post<{ data: CashAccount }>('/api/cash-accounts', payload)
  return data.data
}

export async function updateCashAccount(id: number, payload: CashAccountUpdatePayload): Promise<CashAccount> {
  const { data } = await apiClient.put<{ data: CashAccount }>(`/api/cash-accounts/${id}`, payload)
  return data.data
}

export async function deleteCashAccount(id: number): Promise<void> {
  await apiClient.delete(`/api/cash-accounts/${id}`)
}

export async function setCashAccountActive(id: number, isActive: boolean): Promise<CashAccount> {
  const { data } = await apiClient.patch<{ data: CashAccount }>(`/api/cash-accounts/${id}/status`, { is_active: isActive })
  return data.data
}
