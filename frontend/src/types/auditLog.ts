export type AuditAction = 'created' | 'updated' | 'deleted' | 'login' | 'logout'

export type AuditSubjectType =
  | 'user'
  | 'vehicle'
  | 'vehicle_photo'
  | 'money_entry'
  | 'cash_account'
  | 'customer'
  | 'authentication'

export interface AuditLog {
  id: number
  actor_id: number | null
  actor_name: string | null
  actor_email: string | null
  actor_role: string | null
  action: AuditAction
  subject_type: AuditSubjectType
  subject_id: number | null
  subject_label: string | null
  before_values: Record<string, unknown> | null
  after_values: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  request_method: string | null
  request_path: string | null
  created_at: string
}

export interface AuditLogListParams {
  action?: AuditAction
  subject_type?: AuditSubjectType
  date_from?: string
  date_to?: string
  search?: string
  page?: number
}

export interface AuditLogListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface AuditLogListResponse {
  data: AuditLog[]
  meta: AuditLogListMeta
}
