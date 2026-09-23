import { apiClient, ensureCsrfCookie } from './client'
import type {
  CurrentUserPasswordPayload,
  CurrentUserProfilePayload,
  CurrentUserResponse,
} from '../types/auth'

export async function login(login: string, password: string): Promise<CurrentUserResponse> {
  await ensureCsrfCookie()
  const { data } = await apiClient.post<{ data: CurrentUserResponse }>('/api/login', { login, password })
  return data.data
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/logout')
}

export async function me(): Promise<CurrentUserResponse> {
  const { data } = await apiClient.get<{ data: CurrentUserResponse }>('/api/me')
  return data.data
}

export async function updateCurrentUserProfile(
  payload: CurrentUserProfilePayload,
): Promise<CurrentUserResponse> {
  const { data } = await apiClient.patch<{ data: CurrentUserResponse }>('/api/me/profile', payload)
  return data.data
}

export async function updateCurrentUserPassword(
  payload: CurrentUserPasswordPayload,
): Promise<CurrentUserResponse> {
  const { data } = await apiClient.patch<{ data: CurrentUserResponse }>('/api/me/password', payload)
  return data.data
}
