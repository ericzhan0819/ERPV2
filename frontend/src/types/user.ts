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

// Update only changes metadata; is_active can only be changed via the dedicated
// status endpoint so a stale edit-form submission can never silently undo a
// concurrent deactivation.
export interface UserUpdatePayload {
  name: string
  email: string
  is_admin: boolean
}
