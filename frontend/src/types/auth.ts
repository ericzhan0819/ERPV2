import type { User } from './user'

export type { User } from './user'

export interface CurrentUserProfilePayload {
  name: string
  username: string | null
}

export interface CurrentUserPasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export type CurrentUserResponse = User
