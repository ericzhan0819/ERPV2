import { apiClient } from './client'
import type { User, UserPayload, UserUpdatePayload } from '../types/user'

export async function listUsers(): Promise<User[]> {
  const { data } = await apiClient.get<{ data: User[] }>('/api/users')
  return data.data
}

export async function createUser(payload: UserPayload): Promise<User> {
  const { data } = await apiClient.post<{ data: User }>('/api/users', payload)
  return data.data
}

export async function updateUser(id: number, payload: UserUpdatePayload): Promise<User> {
  const { data } = await apiClient.put<{ data: User }>(`/api/users/${id}`, payload)
  return data.data
}

export async function deleteUser(id: number): Promise<void> {
  await apiClient.delete(`/api/users/${id}`)
}

export async function setUserActive(id: number, isActive: boolean): Promise<User> {
  const { data } = await apiClient.patch<{ data: User }>(`/api/users/${id}/status`, { is_active: isActive })
  return data.data
}

export async function resetUserPassword(id: number, password: string): Promise<void> {
  await apiClient.post(`/api/users/${id}/reset-password`, { password })
}
