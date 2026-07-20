import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { listCashAccountOptions } from '../../api/cashAccounts'
import { approveMoneyEntry, listMoneyEntries, rejectMoneyEntry } from '../../api/moneyEntries'
import { listVehicleOptions } from '../../api/vehicles'
import { useAuth } from '../../hooks/useAuth'
import type { CashAccountOption } from '../../types/cashAccount'
import type { MoneyDirection, MoneyEntry, MoneyEntryApprovalStatus, MoneyEntryListMeta } from '../../types/moneyEntry'
import type { Vehicle } from '../../types/vehicle'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'
import { MoneyDirectionBadge } from '../../components/MoneyDirectionBadge'
import { ApprovalStatusBadge } from '../../components/ApprovalStatusBadge'
import { canApproveMoneyEntries, canViewFinancials } from '../../utils/permissions'
import { parseMoneyEntryListFilters, serializeMoneyEntryListFilters } from '../../utils/listFilters'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

export function MoneyEntryList() {
  const { user } = useAuth()
  const isAdmin = canApproveMoneyEntries(user?.role)
  const canViewFinance = canViewFinancials(user?.role)
  // sales 可以看到自己建立的申請與訂金/尾款/退款等銷售收款安全紀錄的金額（後端已
  // 依角色遮蔽回傳內容），但資金帳戶一律只給 admin/manager。
  const showAmountColumn = canViewFinance || user?.role === 'sales'
  const columnCount = 7 + (showAmountColumn ? 1 : 0) + (canViewFinance ? 1 : 0) + (isAdmin ? 1 : 0)
  const [searchParams, setSearchParams] = useSearchParams()
  const filters = parseMoneyEntryListFilters(searchParams)

  const [entries, setEntries] = useState<MoneyEntry[]>([])
  const [meta, setMeta] = useState<MoneyEntryListMeta | null>(null)
  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reviewingId, setReviewingId] = useState<number | null>(null)

  function updateFilters(
    updates: Partial<typeof filters>,
    options: { resetPage?: boolean; replace?: boolean } = {},
  ) {
    setSearchParams(
      serializeMoneyEntryListFilters({
        ...filters,
        ...updates,
        page: options.resetPage === false ? (updates.page ?? filters.page) : 1,
      }),
      { replace: options.replace ?? false },
    )
  }

  function clearFilters() {
    setSearchParams(new URLSearchParams())
  }

  useEffect(() => {
    listCashAccountOptions().then(setCashAccounts).catch(() => setCashAccounts([]))
    listVehicleOptions().then(setVehicles).catch(() => setVehicles([]))
  }, [])

  const reload = useCallback(() => {
    setLoading(true)
    setError(null)
    listMoneyEntries({
      search: filters.search || undefined,
      direction: filters.direction || undefined,
      category: filters.category || undefined,
      cash_account_id: filters.cashAccountId ?? undefined,
      vehicle_id: filters.vehicleId ?? undefined,
      date_from: filters.dateFrom || undefined,
      date_to: filters.dateTo || undefined,
      approval_status: filters.approvalStatus || undefined,
      page: filters.page,
    })
      .then((response) => {
        setEntries(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('收支列表載入失敗'))
      .finally(() => setLoading(false))
  }, [
    filters.search,
    filters.direction,
    filters.category,
    filters.cashAccountId,
    filters.vehicleId,
    filters.dateFrom,
    filters.dateTo,
    filters.approvalStatus,
    filters.page,
  ])

  useEffect(() => {
    reload()
  }, [reload])

  async function handleApprove(id: number) {
    setReviewingId(id)
    try {
      await approveMoneyEntry(id)
      reload()
    } catch {
      setError('核准失敗，請稍後再試')
    } finally {
      setReviewingId(null)
    }
  }

  async function handleReject(id: number) {
    setReviewingId(id)
    try {
      await rejectMoneyEntry(id)
      reload()
    } catch {
      setError('駁回失敗，請稍後再試')
    } finally {
      setReviewingId(null)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">收支管理</h1>
          <p className="mt-1 text-sm text-fg-muted">一般營運與單車收支紀錄</p>
        </div>
        <div className="flex gap-3">
          <Link
            to="/money-entries/create?direction=income"
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
          >
            新增收入
          </Link>
          <Link
            to="/money-entries/create?direction=expense"
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            新增支出
          </Link>
        </div>
      </div>

      <div className="flex flex-wrap gap-3">
        <input
          type="text"
          placeholder="搜尋對象 / 備註"
          value={filters.search}
          onChange={(event) => updateFilters({ search: event.target.value }, { replace: true })}
          className="w-56 rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={filters.direction}
          onChange={(event) =>
            updateFilters({ direction: event.target.value as MoneyDirection | '', category: '' })
          }
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">收入 / 支出</option>
          <option value="income">{directionLabels.income}</option>
          <option value="expense">{directionLabels.expense}</option>
        </select>
        <select
          value={filters.category}
          onChange={(event) => updateFilters({ category: event.target.value })}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部分類</option>
          {categoriesForDirection(filters.direction).map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          value={filters.cashAccountId ?? ''}
          onChange={(event) =>
            updateFilters({ cashAccountId: event.target.value ? Number(event.target.value) : null })
          }
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部資金帳戶</option>
          {cashAccounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.name}
            </option>
          ))}
        </select>
        <select
          value={filters.vehicleId ?? ''}
          onChange={(event) => updateFilters({ vehicleId: event.target.value ? Number(event.target.value) : null })}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部車輛</option>
          {vehicles.map((vehicle) => (
            <option key={vehicle.id} value={vehicle.id}>
              {vehicle.stock_no}（{vehicle.brand} {vehicle.model}）
            </option>
          ))}
        </select>
        <input
          type="date"
          value={filters.dateFrom}
          onChange={(event) => updateFilters({ dateFrom: event.target.value })}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <input
          type="date"
          value={filters.dateTo}
          onChange={(event) => updateFilters({ dateTo: event.target.value })}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={filters.approvalStatus}
          onChange={(event) =>
            updateFilters({ approvalStatus: event.target.value as MoneyEntryApprovalStatus | '' })
          }
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部審核狀態</option>
          <option value="pending">待審核</option>
          <option value="approved">已核准</option>
          <option value="rejected">已駁回</option>
        </select>
        {searchParams.size > 0 && (
          <button type="button" onClick={clearFilters} className="min-h-11 text-sm font-medium text-primary hover:underline">
            清除篩選條件
          </button>
        )}
      </div>

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">日期</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">收入/支出</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">分類</th>
              {showAmountColumn && <th className="px-4 py-3 text-left font-medium text-fg-muted">金額</th>}
              {canViewFinance && <th className="px-4 py-3 text-left font-medium text-fg-muted">資金帳戶</th>}
              <th className="px-4 py-3 text-left font-medium text-fg-muted">關聯車輛</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">對象</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">備註</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">審核狀態</th>
              {isAdmin && <th className="px-4 py-3 text-left font-medium text-fg-muted">審核操作</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && (
              <tr>
                <td colSpan={columnCount} className="px-4 py-6 text-center text-fg-muted">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && entries.length === 0 && (
              <tr>
                <td colSpan={columnCount} className="px-4 py-6 text-center text-fg-muted">
                  {searchParams.size > 0 ? (
                    <div className="flex flex-col items-center gap-2">
                      <span>尚無符合條件的收支紀錄</span>
                      <button
                        type="button"
                        onClick={clearFilters}
                        className="text-sm font-medium text-primary hover:underline"
                      >
                        清除篩選條件
                      </button>
                    </div>
                  ) : (
                    <div className="flex flex-col items-center gap-2">
                      <span>尚無收支紀錄</span>
                      <Link to="/money-entries/create" className="text-sm font-medium text-primary hover:underline">
                        新增第一筆收支
                      </Link>
                    </div>
                  )}
                </td>
              </tr>
            )}
            {!loading &&
              entries.map((entry) => (
                <tr key={entry.id} className="hover:bg-surface-2">
                  <td className="px-4 py-3">{entry.entry_date}</td>
                  <td className="px-4 py-3">
                    <MoneyDirectionBadge direction={entry.direction} />
                  </td>
                  <td className="px-4 py-3">{entry.category}</td>
                  {showAmountColumn && (
                    <td className="px-4 py-3 tabular-nums">
                      {entry.amount === undefined ? '-' : currencyFormatter.format(entry.amount)}
                    </td>
                  )}
                  {canViewFinance && <td className="px-4 py-3">{entry.cash_account?.name ?? '-'}</td>}
                  <td className="px-4 py-3">
                    {entry.vehicle ? (
                      <Link to={`/vehicles/${entry.vehicle.id}`} className="text-fg hover:underline">
                        {entry.vehicle.stock_no}
                      </Link>
                    ) : (
                      '-'
                    )}
                  </td>
                  <td className="px-4 py-3">{entry.counterparty_name ?? '-'}</td>
                  <td className="px-4 py-3">{entry.description ?? '-'}</td>
                  <td className="px-4 py-3">
                    <ApprovalStatusBadge status={entry.approval_status} />
                  </td>
                  {isAdmin && (
                    <td className="px-4 py-3">
                      {entry.approval_status === 'pending' ? (
                        <div className="flex gap-2">
                          <button
                            type="button"
                            disabled={reviewingId === entry.id}
                            onClick={() => handleApprove(entry.id)}
                            className="rounded-lg border border-border-strong px-2.5 py-1 text-xs font-medium text-success hover:bg-surface-2 disabled:opacity-50"
                          >
                            核准
                          </button>
                          <button
                            type="button"
                            disabled={reviewingId === entry.id}
                            onClick={() => handleReject(entry.id)}
                            className="rounded-lg border border-border-strong px-2.5 py-1 text-xs font-medium text-error hover:bg-surface-2 disabled:opacity-50"
                          >
                            駁回
                          </button>
                        </div>
                      ) : (
                        '-'
                      )}
                    </td>
                  )}
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-fg-muted">
          <span>
            第 {meta.current_page} / {meta.last_page} 頁，共 {meta.total} 筆
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => updateFilters({ page: Math.max(1, filters.page - 1) }, { resetPage: false })}
              disabled={meta.current_page <= 1}
              className="rounded-lg border border-border-strong px-3 py-1.5 disabled:opacity-50"
            >
              上一頁
            </button>
            <button
              type="button"
              onClick={() => updateFilters({ page: Math.min(meta.last_page, filters.page + 1) }, { resetPage: false })}
              disabled={meta.current_page >= meta.last_page}
              className="rounded-lg border border-border-strong px-3 py-1.5 disabled:opacity-50"
            >
              下一頁
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
