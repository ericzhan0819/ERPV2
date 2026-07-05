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

// Update only changes metadata; is_active and role can only be changed via
// their dedicated endpoints so a stale edit-form submission can never silently
// undo a concurrent deactivation or re-grant/revoke a role.
export interface UserUpdatePayload {
  name: string
  email: string
  phone?: string | null
  job_title?: string | null
  hire_date?: string | null
  notes?: string | null
}
