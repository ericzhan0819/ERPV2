export interface User {
  id: number
  name: string
  email: string
  is_admin: boolean
  is_active: boolean
}

export interface UserPayload {
  name: string
  email: string
  password: string
  is_admin: boolean
}

// Update only changes metadata; is_active and is_admin can only be changed via
// their dedicated endpoints so a stale edit-form submission can never silently
// undo a concurrent deactivation or re-grant/revoke admin rights.
export interface UserUpdatePayload {
  name: string
  email: string
}
