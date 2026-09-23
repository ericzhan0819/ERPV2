export interface CashAccountOption {
  id: number
  name: string
  type: string
  is_active: boolean
}

export type CashAccountType = 'cash' | 'bank' | 'other'

export interface CashAccount {
  id: number
  name: string
  type: CashAccountType
  opening_balance: number
  is_active: boolean
}

export interface CashAccountBalance extends CashAccount {
  current_balance: number
}

export interface CashAccountPayload {
  name: string
  type: CashAccountType
  opening_balance: number
  is_active: boolean
}

// 一般更新只可修改帳戶資料；啟用狀態必須走專用端點，避免舊表單覆蓋並行停用。
export interface CashAccountUpdatePayload {
  name: string
  type: CashAccountType
  opening_balance: number
}
