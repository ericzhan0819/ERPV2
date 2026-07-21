import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { SlidersHorizontal } from 'lucide-react'
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
import { ActiveFilterChip } from '../../components/ActiveFilterChip'
import { DebouncedSearchInput } from '../../components/DebouncedSearchInput'
import { MobileFilterDrawer } from '../../components/MobileFilterDrawer'
import { canApproveMoneyEntries, canViewFinancials } from '../../utils/permissions'
import {
  hasActiveMoneyEntryListFilters,
  parseMoneyEntryListFilters,
  serializeMoneyEntryListFilters,
  type MoneyEntryListFilters,
} from '../../utils/listFilters'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

const approvalStatusLabels: Record<MoneyEntryApprovalStatus, string> = {
  pending: '待審核',
  approved: '已核准',
  rejected: '已駁回',
}

type MoneyFilterChangeHandler = (
  updates: Partial<MoneyEntryListFilters>,
  options?: { replace?: boolean },
) => void

function MoneyEntryFilterFields({
  filters,
  cashAccounts,
  vehicles,
  onChange,
  debounceSearch = false,
  idPrefix,
}: {
  filters: MoneyEntryListFilters
  cashAccounts: CashAccountOption[]
  vehicles: Vehicle[]
  onChange: MoneyFilterChangeHandler
  debounceSearch?: boolean
  idPrefix: string
}) {
  const fieldClassName = 'min-h-11 w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-base text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring sm:text-sm'

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
      {debounceSearch ? (
        <DebouncedSearchInput
          id={`${idPrefix}-search`}
          label="搜尋收支"
          placeholder="對象 / 備註"
          value={filters.search}
          onCommit={(search) => onChange({ search }, { replace: true })}
          className={`${fieldClassName} sm:w-56`}
        />
      ) : (
        <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
          搜尋收支
          <input
            type="search"
            placeholder="對象 / 備註"
            value={filters.search}
            onChange={(event) => onChange({ search: event.target.value })}
            className={`${fieldClassName} sm:w-56`}
          />
        </label>
      )}

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        收支方向
        <select
          value={filters.direction}
          onChange={(event) => onChange({ direction: event.target.value as MoneyDirection | '', category: '' })}
          className={fieldClassName}
        >
          <option value="">全部方向</option>
          <option value="income">{directionLabels.income}</option>
          <option value="expense">{directionLabels.expense}</option>
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        分類
        <select value={filters.category} onChange={(event) => onChange({ category: event.target.value })} className={fieldClassName}>
          <option value="">全部分類</option>
          {categoriesForDirection(filters.direction).map((category) => <option key={category} value={category}>{category}</option>)}
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        資金帳戶
        <select
          value={filters.cashAccountId ?? ''}
          onChange={(event) => onChange({ cashAccountId: event.target.value ? Number(event.target.value) : null })}
          className={fieldClassName}
        >
          <option value="">全部資金帳戶</option>
          {cashAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        關聯車輛
        <select
          value={filters.vehicleId ?? ''}
          onChange={(event) => onChange({ vehicleId: event.target.value ? Number(event.target.value) : null })}
          className={fieldClassName}
        >
          <option value="">全部車輛</option>
          {vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.stock_no}（{vehicle.brand} {vehicle.model}）</option>)}
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        起始日期
        <input type="date" value={filters.dateFrom} onChange={(event) => onChange({ dateFrom: event.target.value })} className={fieldClassName} />
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        結束日期
        <input type="date" value={filters.dateTo} onChange={(event) => onChange({ dateTo: event.target.value })} className={fieldClassName} />
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        審核狀態
        <select
          value={filters.approvalStatus}
          onChange={(event) => onChange({ approvalStatus: event.target.value as MoneyEntryApprovalStatus | '' })}
          className={fieldClassName}
        >
          <option value="">全部審核狀態</option>
          <option value="pending">待審核</option>
          <option value="approved">已核准</option>
          <option value="rejected">已駁回</option>
        </select>
      </label>
    </div>
  )
}

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
  const filterUrlKey = searchParams.toString()

  const [entries, setEntries] = useState<MoneyEntry[]>([])
  const [meta, setMeta] = useState<MoneyEntryListMeta | null>(null)
  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reviewingId, setReviewingId] = useState<number | null>(null)
  const [refreshToken, setRefreshToken] = useState(0)
  const [filterDrawerOpen, setFilterDrawerOpen] = useState(false)
  const [draftFilters, setDraftFilters] = useState<MoneyEntryListFilters>(() => ({ ...filters }))
  const filterDrawerTriggerRef = useRef<HTMLButtonElement>(null)
  const requestSequenceRef = useRef(0)
  const hasActiveFilters = hasActiveMoneyEntryListFilters(filters)
  const isPageOutOfRange = Boolean(meta && filters.page > meta.last_page)

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

  function openFilterDrawer() {
    setDraftFilters({ ...filters })
    setFilterDrawerOpen(true)
  }

  function applyDraftFilters() {
    setSearchParams(serializeMoneyEntryListFilters({ ...draftFilters, page: 1 }))
    setFilterDrawerOpen(false)
  }

  function clearDraftFilters() {
    setDraftFilters({
      search: '',
      direction: '',
      category: '',
      cashAccountId: null,
      vehicleId: null,
      dateFrom: '',
      dateTo: '',
      approvalStatus: '',
      page: 1,
    })
  }

  useEffect(() => {
    if (!filterDrawerOpen) setDraftFilters(parseMoneyEntryListFilters(new URLSearchParams(filterUrlKey)))
  }, [filterDrawerOpen, filterUrlKey])

  useEffect(() => {
    listCashAccountOptions().then(setCashAccounts).catch(() => setCashAccounts([]))
    listVehicleOptions().then(setVehicles).catch(() => setVehicles([]))
  }, [])

  useEffect(() => {
    const requestSequence = ++requestSequenceRef.current
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
        if (requestSequence !== requestSequenceRef.current) return
        setEntries(response.data)
        setMeta(response.meta)
      })
      .catch(() => {
        if (requestSequence === requestSequenceRef.current) setError('收支列表載入失敗')
      })
      .finally(() => {
        if (requestSequence === requestSequenceRef.current) setLoading(false)
      })

    return () => {
      requestSequenceRef.current += 1
    }
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
    refreshToken,
  ])

  async function handleApprove(id: number) {
    setReviewingId(id)
    try {
      await approveMoneyEntry(id)
      setRefreshToken((token) => token + 1)
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
      setRefreshToken((token) => token + 1)
    } catch {
      setError('駁回失敗，請稍後再試')
    } finally {
      setReviewingId(null)
    }
  }

  const activeFilterCount = [
    filters.search,
    filters.direction,
    filters.category,
    filters.cashAccountId,
    filters.vehicleId,
    filters.dateFrom,
    filters.dateTo,
    filters.approvalStatus,
  ].filter(Boolean).length
  const selectedAccount = cashAccounts.find((account) => account.id === filters.cashAccountId)
  const selectedVehicle = vehicles.find((vehicle) => vehicle.id === filters.vehicleId)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">收支管理</h1>
          <p className="mt-1 text-sm text-fg-muted">一般營運與單車收支紀錄</p>
        </div>
        <div className="flex flex-wrap gap-3">
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

      <div className="hidden sm:block">
        <MoneyEntryFilterFields
          idPrefix="money-desktop"
          filters={draftFilters}
          cashAccounts={cashAccounts}
          vehicles={vehicles}
          debounceSearch
          onChange={(updates, options) => {
            setDraftFilters((current) => ({ ...current, ...updates, page: 1 }))
            updateFilters(updates, { replace: options?.replace })
          }}
        />
        {hasActiveFilters && (
          <button type="button" onClick={clearFilters} className="mt-3 min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            清除篩選條件
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3 sm:hidden">
        <button
          ref={filterDrawerTriggerRef}
          type="button"
          aria-expanded={filterDrawerOpen}
          aria-controls="money-entry-filter-drawer"
          onClick={openFilterDrawer}
          className="flex min-h-11 items-center gap-2 rounded-lg border border-border-strong bg-surface px-4 text-sm font-medium text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <SlidersHorizontal aria-hidden className="h-4 w-4" />
          篩選
          {activeFilterCount > 0 && <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-fg">{activeFilterCount}</span>}
        </button>
        {hasActiveFilters && <button type="button" onClick={clearFilters} className="min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">清除全部</button>}
      </div>

      {hasActiveFilters && (
        <div className="flex flex-wrap items-center gap-2" aria-label="已套用篩選條件">
          {filters.search && <ActiveFilterChip label={`搜尋：${filters.search}`} onRemove={() => updateFilters({ search: '' })} />}
          {filters.direction && <ActiveFilterChip label={`方向：${directionLabels[filters.direction]}`} onRemove={() => updateFilters({ direction: '', category: '' })} />}
          {filters.category && <ActiveFilterChip label={`分類：${filters.category}`} onRemove={() => updateFilters({ category: '' })} />}
          {filters.cashAccountId && <ActiveFilterChip label={`資金帳戶：${selectedAccount?.name ?? `#${filters.cashAccountId}`}`} onRemove={() => updateFilters({ cashAccountId: null })} />}
          {filters.vehicleId && <ActiveFilterChip label={`車輛：${selectedVehicle?.stock_no ?? `#${filters.vehicleId}`}`} onRemove={() => updateFilters({ vehicleId: null })} />}
          {filters.dateFrom && <ActiveFilterChip label={`起始日期：${filters.dateFrom}`} onRemove={() => updateFilters({ dateFrom: '' })} />}
          {filters.dateTo && <ActiveFilterChip label={`結束日期：${filters.dateTo}`} onRemove={() => updateFilters({ dateTo: '' })} />}
          {filters.approvalStatus && <ActiveFilterChip label={`審核：${approvalStatusLabels[filters.approvalStatus]}`} onRemove={() => updateFilters({ approvalStatus: '' })} />}
        </div>
      )}

      <MobileFilterDrawer
        id="money-entry-filter-drawer"
        title="篩選收支"
        open={filterDrawerOpen}
        triggerRef={filterDrawerTriggerRef}
        onClose={() => setFilterDrawerOpen(false)}
        footer={(
          <>
            <button type="button" onClick={clearDraftFilters} className="min-h-11 rounded-lg border border-border-strong px-4 text-sm font-medium text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">清除全部</button>
            <button type="button" onClick={applyDraftFilters} className="min-h-11 rounded-lg bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">套用篩選</button>
          </>
        )}
      >
        <MoneyEntryFilterFields
          idPrefix="money-mobile"
          filters={draftFilters}
          cashAccounts={cashAccounts}
          vehicles={vehicles}
          onChange={(updates) => setDraftFilters((current) => ({ ...current, ...updates, page: 1 }))}
        />
      </MobileFilterDrawer>

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
                  {isPageOutOfRange ? (
                    <div className="flex flex-col items-center gap-2">
                      <span>此頁沒有資料</span>
                      <button
                        type="button"
                        onClick={() => updateFilters({ page: 1 }, { resetPage: false })}
                        className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      >
                        回到第 1 頁
                      </button>
                    </div>
                  ) : hasActiveFilters ? (
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
