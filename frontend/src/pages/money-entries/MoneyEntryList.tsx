import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { listCashAccountOptions } from '../../api/cashAccounts'
import { approveMoneyEntry, listMoneyEntries, rejectMoneyEntry } from '../../api/moneyEntries'
import { useAuth } from '../../hooks/useAuth'
import type { CashAccountOption } from '../../types/cashAccount'
import type { MoneyDirection, MoneyEntry, MoneyEntryApprovalStatus, MoneyEntryListMeta } from '../../types/moneyEntry'
import type { Vehicle, VehicleListResponse } from '../../types/vehicle'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'
import { MoneyDirectionBadge } from '../../components/MoneyDirectionBadge'
import { ApprovalStatusBadge } from '../../components/ApprovalStatusBadge'
import { canApproveMoneyEntries, canViewFinancials } from '../../utils/permissions'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

export function MoneyEntryList() {
  const { user } = useAuth()
  const isAdmin = canApproveMoneyEntries(user?.role)
  const canViewFinance = canViewFinancials(user?.role)
  // sales 可以看到自己建立的申請與訂金/尾款/退款等銷售收款安全紀錄的金額（後端已
  // 依角色遮蔽回傳內容），但資金帳戶一律只給 admin/manager。
  const showAmountColumn = canViewFinance || user?.role === 'sales'
  const columnCount = 7 + (showAmountColumn ? 1 : 0) + (canViewFinance ? 1 : 0) + (isAdmin ? 1 : 0)

  const [entries, setEntries] = useState<MoneyEntry[]>([])
  const [meta, setMeta] = useState<MoneyEntryListMeta | null>(null)
  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])

  const [search, setSearch] = useState('')
  const [direction, setDirection] = useState<MoneyDirection | ''>('')
  const [category, setCategory] = useState('')
  const [cashAccountId, setCashAccountId] = useState('')
  const [vehicleId, setVehicleId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [approvalStatus, setApprovalStatus] = useState<MoneyEntryApprovalStatus | ''>('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reviewingId, setReviewingId] = useState<number | null>(null)

  useEffect(() => {
    listCashAccountOptions().then(setCashAccounts).catch(() => setCashAccounts([]))
    apiClient
      .get<VehicleListResponse>('/api/vehicles', { params: { per_page: 100 } })
      .then((response) => setVehicles(response.data.data))
      .catch(() => setVehicles([]))
  }, [])

  const reload = useCallback(() => {
    setLoading(true)
    setError(null)
    listMoneyEntries({
      search: search || undefined,
      direction: direction || undefined,
      category: category || undefined,
      cash_account_id: cashAccountId ? Number(cashAccountId) : undefined,
      vehicle_id: vehicleId ? Number(vehicleId) : undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      approval_status: approvalStatus || undefined,
      page,
    })
      .then((response) => {
        setEntries(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('收支列表載入失敗'))
      .finally(() => setLoading(false))
  }, [search, direction, category, cashAccountId, vehicleId, dateFrom, dateTo, approvalStatus, page])

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
          value={search}
          onChange={(e) => {
            setPage(1)
            setSearch(e.target.value)
          }}
          className="w-56 rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={direction}
          onChange={(e) => {
            setPage(1)
            setDirection(e.target.value as MoneyDirection | '')
            setCategory('')
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">收入 / 支出</option>
          <option value="income">{directionLabels.income}</option>
          <option value="expense">{directionLabels.expense}</option>
        </select>
        <select
          value={category}
          onChange={(e) => {
            setPage(1)
            setCategory(e.target.value)
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部分類</option>
          {categoriesForDirection(direction).map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          value={cashAccountId}
          onChange={(e) => {
            setPage(1)
            setCashAccountId(e.target.value)
          }}
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
          value={vehicleId}
          onChange={(e) => {
            setPage(1)
            setVehicleId(e.target.value)
          }}
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
          value={dateFrom}
          onChange={(e) => {
            setPage(1)
            setDateFrom(e.target.value)
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <input
          type="date"
          value={dateTo}
          onChange={(e) => {
            setPage(1)
            setDateTo(e.target.value)
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={approvalStatus}
          onChange={(e) => {
            setPage(1)
            setApprovalStatus(e.target.value as MoneyEntryApprovalStatus | '')
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          <option value="">全部審核狀態</option>
          <option value="pending">待審核</option>
          <option value="approved">已核准</option>
          <option value="rejected">已駁回</option>
        </select>
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
                  {search || direction || category || cashAccountId || vehicleId || dateFrom || dateTo || approvalStatus ? (
                    <div className="flex flex-col items-center gap-2">
                      <span>尚無符合條件的收支紀錄</span>
                      <button
                        type="button"
                        onClick={() => {
                          setSearch('')
                          setDirection('')
                          setCategory('')
                          setCashAccountId('')
                          setVehicleId('')
                          setDateFrom('')
                          setDateTo('')
                          setApprovalStatus('')
                          setPage(1)
                        }}
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
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={meta.current_page <= 1}
              className="rounded-lg border border-border-strong px-3 py-1.5 disabled:opacity-50"
            >
              上一頁
            </button>
            <button
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
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
