import { apiClient, ensureCsrfCookie } from './client'
import type { User } from '../types/auth'

export async function login(login: string, password: string): Promise<User> {
  await ensureCsrfCookie()
  const { data } = await apiClient.post<{ data: User }>('/api/login', { login, password })
  return data.data
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/logout')
}

export async function me(): Promise<User> {
  const { data } = await apiClient.get<{ data: User }>('/api/me')
  return data.data
}
