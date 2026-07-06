import { Fragment, useCallback, useEffect, useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { listAuditLogs } from '../../api/auditLogs'
import type {
  AuditAction,
  AuditLog,
  AuditLogListMeta,
  AuditSubjectType,
} from '../../types/auditLog'

const actionLabels: Record<AuditAction, string> = {
  created: '新增',
  updated: '修改',
  deleted: '刪除',
  login: '登入',
  logout: '登出',
}

const subjectLabels: Record<AuditSubjectType, string> = {
  user: '員工帳號',
  vehicle: '車輛',
  money_entry: '收支',
  cash_account: '資金帳戶',
  customer: '客戶',
  authentication: '認證',
}

const roleLabels: Record<string, string> = {
  admin: '管理員',
  manager: '經理',
  sales: '業務',
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat('zh-TW', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).format(new Date(value))
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}

function ChangeDetails({ log }: { log: AuditLog }) {
  const keys = Array.from(
    new Set([...Object.keys(log.before_values ?? {}), ...Object.keys(log.after_values ?? {})]),
  )

  return (
    <div className="grid gap-4 bg-surface-2 p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <div>
        <p className="mb-2 text-sm font-medium text-fg">異動內容</p>
        {keys.length === 0 ? (
          <p className="text-sm text-fg-muted">此操作沒有可顯示的欄位異動。</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-2">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-fg-muted">欄位</th>
                  <th className="px-3 py-2 text-left font-medium text-fg-muted">異動前</th>
                  <th className="px-3 py-2 text-left font-medium text-fg-muted">異動後</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {keys.map((key) => (
                  <tr key={key}>
                    <td className="whitespace-nowrap px-3 py-2 font-medium text-fg">{key}</td>
                    <td className="max-w-sm whitespace-pre-wrap break-words px-3 py-2 text-fg-muted">
                      {formatValue(log.before_values?.[key])}
                    </td>
                    <td className="max-w-sm whitespace-pre-wrap break-words px-3 py-2 text-fg">
                      {formatValue(log.after_values?.[key])}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      <div className="space-y-2 text-sm">
        <p className="font-medium text-fg">請求資訊</p>
        <p className="text-fg-muted">
          <span className="font-medium text-fg">路徑：</span>
          {log.request_method && log.request_path ? `${log.request_method} /${log.request_path}` : '—'}
        </p>
        <p className="text-fg-muted">
          <span className="font-medium text-fg">IP：</span>
          {log.ip_address ?? '—'}
        </p>
        <p className="break-words text-fg-muted">
          <span className="font-medium text-fg">User-Agent：</span>
          {log.user_agent ?? '—'}
        </p>
      </div>
    </div>
  )
}

export function AuditLogList() {
  const [logs, setLogs] = useState<AuditLog[]>([])
  const [meta, setMeta] = useState<AuditLogListMeta | null>(null)
  const [search, setSearch] = useState('')
  const [action, setAction] = useState<AuditAction | ''>('')
  const [subjectType, setSubjectType] = useState<AuditSubjectType | ''>('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [expandedId, setExpandedId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const reload = useCallback(() => {
    setLoading(true)
    setError(null)

    listAuditLogs({
      search: search || undefined,
      action: action || undefined,
      subject_type: subjectType || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
    })
      .then((response) => {
        setLogs(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('稽核紀錄載入失敗'))
      .finally(() => setLoading(false))
  }, [action, dateFrom, dateTo, page, search, subjectType])

  useEffect(() => {
    reload()
  }, [reload])

  function resetPage() {
    setPage(1)
    setExpandedId(null)
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-fg">稽核紀錄</h1>
        <p className="mt-1 text-sm text-fg-muted">追蹤系統登入、資料新增、修改與刪除操作；紀錄僅供查詢。</p>
      </div>

      <div className="flex flex-wrap gap-3 rounded-2xl border border-border bg-surface p-4 shadow-sm">
        <input
          type="search"
          placeholder="搜尋操作者或操作對象"
          value={search}
          onChange={(event) => {
            resetPage()
            setSearch(event.target.value)
          }}
          className="w-64 rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={action}
          onChange={(event) => {
            resetPage()
            setAction(event.target.value as AuditAction | '')
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部操作</option>
          {Object.entries(actionLabels).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
        <select
          value={subjectType}
          onChange={(event) => {
            resetPage()
            setSubjectType(event.target.value as AuditSubjectType | '')
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部資料類型</option>
          {Object.entries(subjectLabels).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
        <input
          type="date"
          aria-label="開始日期"
          value={dateFrom}
          onChange={(event) => {
            resetPage()
            setDateFrom(event.target.value)
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <input
          type="date"
          aria-label="結束日期"
          value={dateTo}
          onChange={(event) => {
            resetPage()
            setDateTo(event.target.value)
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
      </div>

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-2">
              <tr>
                <th className="w-10 px-3 py-3" aria-label="展開" />
                <th className="px-3 py-3 text-left font-medium text-fg-muted">時間</th>
                <th className="px-3 py-3 text-left font-medium text-fg-muted">操作者</th>
                <th className="px-3 py-3 text-left font-medium text-fg-muted">操作</th>
                <th className="px-3 py-3 text-left font-medium text-fg-muted">資料類型</th>
                <th className="px-3 py-3 text-left font-medium text-fg-muted">操作對象</th>
                <th className="px-3 py-3 text-left font-medium text-fg-muted">IP</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {loading && (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-fg-muted">載入中...</td>
                </tr>
              )}
              {!loading && logs.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-fg-muted">尚無符合條件的稽核紀錄</td>
                </tr>
              )}
              {!loading && logs.map((log) => {
                const expanded = expandedId === log.id
                return (
                  <Fragment key={log.id}>
                    <tr className="hover:bg-surface-2">
                      <td className="px-3 py-3">
                        <button
                          type="button"
                          aria-label={expanded ? '收合異動內容' : '展開異動內容'}
                          onClick={() => setExpandedId(expanded ? null : log.id)}
                          className="rounded p-1 text-fg-muted hover:bg-surface-2 hover:text-fg"
                        >
                          {expanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                        </button>
                      </td>
                      <td className="whitespace-nowrap px-3 py-3 text-fg-muted">{formatDateTime(log.created_at)}</td>
                      <td className="px-3 py-3">
                        <p className="font-medium text-fg">{log.actor_name ?? '系統'}</p>
                        <p className="text-xs text-fg-muted">
                          {log.actor_role ? roleLabels[log.actor_role] ?? log.actor_role : '無登入使用者'}
                        </p>
                      </td>
                      <td className="whitespace-nowrap px-3 py-3 font-medium text-fg">{actionLabels[log.action]}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-fg-muted">{subjectLabels[log.subject_type]}</td>
                      <td className="px-3 py-3 text-fg">{log.subject_label ?? `#${log.subject_id ?? '—'}`}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-fg-muted">{log.ip_address ?? '—'}</td>
                    </tr>
                    {expanded && (
                      <tr>
                        <td colSpan={7} className="p-0">
                          <ChangeDetails log={log} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-border px-4 py-3">
            <span className="text-sm text-fg-muted">共 {meta.total} 筆</span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                disabled={meta.current_page <= 1}
                onClick={() => {
                  setExpandedId(null)
                  setPage((current) => Math.max(1, current - 1))
                }}
                className="rounded-lg border border-border-strong px-3 py-1.5 text-sm text-fg disabled:opacity-40"
              >
                上一頁
              </button>
              <span className="text-sm text-fg-muted">{meta.current_page} / {meta.last_page}</span>
              <button
                type="button"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => {
                  setExpandedId(null)
                  setPage((current) => Math.min(meta.last_page, current + 1))
                }}
                className="rounded-lg border border-border-strong px-3 py-1.5 text-sm text-fg disabled:opacity-40"
              >
                下一頁
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
