import { apiClient } from './client'
import type {
  Customer,
  CustomerDetailResponse,
  CustomerListParams,
  CustomerListResponse,
  CustomerPayload,
} from '../types/customer'

export async function listCustomers(params: CustomerListParams): Promise<CustomerListResponse> {
  const { data } = await apiClient.get<CustomerListResponse>('/api/customers', { params })
  return data
}

export async function getCustomer(id: number): Promise<CustomerDetailResponse> {
  const { data } = await apiClient.get<CustomerDetailResponse>(`/api/customers/${id}`)
  return data
}

export async function createCustomer(payload: CustomerPayload): Promise<Customer> {
  const { data } = await apiClient.post<{ data: Customer }>('/api/customers', payload)
  return data.data
}

export async function updateCustomer(id: number, payload: CustomerPayload): Promise<Customer> {
  const { data } = await apiClient.put<{ data: Customer }>(`/api/customers/${id}`, payload)
  return data.data
}

export async function deleteCustomer(id: number): Promise<void> {
  await apiClient.delete(`/api/customers/${id}`)
}
