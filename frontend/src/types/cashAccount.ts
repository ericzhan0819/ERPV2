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

// Update only changes metadata; is_active can only be changed via the dedicated
// status endpoint so a stale edit-form submission can never silently undo a
// concurrent deactivation.
export interface CashAccountUpdatePayload {
  name: string
  type: CashAccountType
  opening_balance: number
}
