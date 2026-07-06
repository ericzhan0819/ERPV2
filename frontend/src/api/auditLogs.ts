import { apiClient } from './client'
import type { AuditLog, AuditLogListParams, AuditLogListResponse } from '../types/auditLog'

export async function listAuditLogs(params: AuditLogListParams): Promise<AuditLogListResponse> {
  const { data } = await apiClient.get<AuditLogListResponse>('/api/audit-logs', { params })
  return data
}

export async function getAuditLog(id: number): Promise<AuditLog> {
  const { data } = await apiClient.get<{ data: AuditLog }>(`/api/audit-logs/${id}`)
  return data.data
}
