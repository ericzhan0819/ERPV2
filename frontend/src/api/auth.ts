import { apiClient, ensureCsrfCookie } from './client'
import type { User } from '../types/auth'

export async function login(email: string, password: string): Promise<User> {
  await ensureCsrfCookie()
  const { data } = await apiClient.post<User>('/api/login', { email, password })
  return data
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/logout')
}

export async function me(): Promise<User> {
  const { data } = await apiClient.get<User>('/api/me')
  return data
}
