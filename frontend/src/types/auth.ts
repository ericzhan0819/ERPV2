import type { UserRole } from './user'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  is_admin: boolean
  is_active: boolean
}
