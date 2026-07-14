export type UserRole = 'admin' | 'manager' | 'sales'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  is_admin: boolean
  is_active: boolean
  phone: string | null
  job_title: string | null
  hire_date: string | null
  notes: string | null
}

export interface UserPayload {
  name: string
  email: string
  password: string
  role: UserRole
  phone?: string | null
  job_title?: string | null
  hire_date?: string | null
  notes?: string | null
}

// 一般更新只可修改基本資料；啟用狀態與角色必須走專用端點，避免舊表單覆蓋並行變更。
export interface UserUpdatePayload {
  name: string
  email: string
  phone?: string | null
  job_title?: string | null
  hire_date?: string | null
  notes?: string | null
}
