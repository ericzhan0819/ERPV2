import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ImageOff, SlidersHorizontal } from 'lucide-react'
import { isAxiosError } from 'axios'
import { listVehicles } from '../../api/vehicles'
import type { VehicleListItem, VehicleListMeta, VehicleStatus } from '../../types/vehicle'
import { VehicleStatusBadge } from '../../components/VehicleStatusBadge'
import { ActiveFilterChip } from '../../components/ActiveFilterChip'
import { DebouncedSearchInput } from '../../components/DebouncedSearchInput'
import { MobileFilterDrawer } from '../../components/MobileFilterDrawer'
import { useAuth } from '../../hooks/useAuth'
import { canManageVehicles, canViewFinancials } from '../../utils/permissions'
import {
  defaultVehicleStatuses,
  hasActiveVehicleListFilters,
  hasDefaultVehicleStatuses,
  parseVehicleListFilters,
  serializeVehicleListFilters,
  type VehicleListFilters,
  vehicleStatuses,
} from '../../utils/listFilters'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null | undefined): string {
  return amount === null || amount === undefined ? '—' : currencyFormatter.format(amount)
}

const statusLabels: Record<VehicleStatus, string> = {
  preparing: '整備中',
  listed: '上架中',
  reserved: '保留中',
  sold: '已售出',
  cancelled: '取消 / 退車',
}

const soldMonthPattern = /^(\d{4})-(0[1-9]|1[0-2])$/

type FilterChangeHandler = (
  updates: Partial<VehicleListFilters>,
  options?: { replace?: boolean },
) => void

function formatSoldMonth(value: string): string {
  const match = soldMonthPattern.exec(value)
  return match ? `${match[1]} 年 ${Number(match[2])} 月` : value
}

function vehicleListError(error: unknown): string {
  if (
    isAxiosError<{ errors?: Record<string, string[]> }>(error) &&
    error.response?.status === 422 &&
    error.response.data.errors?.sold_month
  ) {
    return '成交月份格式錯誤，請清除成交月份後重試。'
  }

  return '車輛列表載入失敗'
}

function VehicleFilterFields({
  filters,
  onChange,
  debounceSearch = false,
}: {
  filters: VehicleListFilters
  onChange: FilterChangeHandler
  debounceSearch?: boolean
}) {
  function toggleStatus(status: VehicleStatus, checked: boolean) {
    const nextStatuses = checked
      ? vehicleStatuses.filter((candidate) => filters.statuses.includes(candidate) || candidate === status)
      : filters.statuses.filter((candidate) => candidate !== status)

    if (nextStatuses.length > 0) onChange({ statuses: nextStatuses })
  }

  const searchClassName = 'min-h-11 w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-base text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring sm:w-72 sm:text-sm'

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
      {debounceSearch ? (
        <DebouncedSearchInput
          id="vehicle-search"
          label="搜尋車輛"
          placeholder="庫存編號 / 廠牌 / 車型 / 車牌 / VIN"
          value={filters.search}
          onCommit={(search) => onChange({ search }, { replace: true })}
          className={searchClassName}
        />
      ) : (
        <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
          搜尋車輛
        <input
          type="search"
          placeholder="庫存編號 / 廠牌 / 車型 / 車牌 / VIN"
          value={filters.search}
          onChange={(event) => onChange({ search: event.target.value }, { replace: true })}
          className={searchClassName}
        />
        </label>
      )}

      <fieldset className="flex flex-wrap gap-x-3 gap-y-1">
        <legend className="mb-1 text-sm font-medium text-fg-muted">車輛狀態</legend>
        {vehicleStatuses.map((status) => (
          <label key={status} className="flex min-h-11 items-center gap-2 text-sm text-fg">
            <input
              type="checkbox"
              checked={filters.statuses.includes(status)}
              disabled={filters.statuses.length === 1 && filters.statuses.includes(status)}
              onChange={(event) => toggleStatus(status, event.target.checked)}
              className="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
            />
            {statusLabels[status]}
          </label>
        ))}
        <p className="w-full text-xs text-fg-subtle">至少保留一個車輛狀態。</p>
      </fieldset>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        整備狀態
        <select
          value={filters.isPreparationCompleted === undefined ? '' : String(filters.isPreparationCompleted)}
          onChange={(event) => {
            const value = event.target.value
            onChange({ isPreparationCompleted: value === '' ? undefined : value === 'true' })
          }}
          className="min-h-11 rounded-lg border border-border-strong bg-surface px-3 py-2 text-base text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring sm:text-sm"
        >
          <option value="">全部整備狀態</option>
          <option value="false">整備未完成</option>
          <option value="true">整備已完成</option>
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
        成交月份
        <input
          type="month"
          value={soldMonthPattern.test(filters.soldMonth) ? filters.soldMonth : ''}
          onChange={(event) => onChange({ soldMonth: event.target.value })}
          className="min-h-11 rounded-lg border border-border-strong bg-surface px-3 py-2 text-base text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring sm:text-sm"
        />
      </label>

      {filters.soldMonth && (
        <button
          type="button"
          onClick={() => onChange({ soldMonth: '' })}
          className="min-h-11 self-start text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:self-auto"
        >
          清除成交月份
        </button>
      )}
    </div>
  )
}

function VehicleCover({ vehicle }: { vehicle: VehicleListItem }) {
  const thumbnailUrl = vehicle.cover_photo?.thumbnail_url
  const [failedUrl, setFailedUrl] = useState<string | null>(null)

  if (thumbnailUrl && failedUrl !== thumbnailUrl) {
    return (
      <img
        src={thumbnailUrl}
        alt={`${vehicle.brand} ${vehicle.model} 封面照片`}
        className="aspect-[4/3] w-full bg-surface-2 object-cover"
        onError={() => setFailedUrl(thumbnailUrl)}
      />
    )
  }

  return (
    <div className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 bg-surface-2 text-fg-muted">
      <ImageOff aria-hidden className="h-9 w-9 text-fg-subtle" strokeWidth={1.5} />
      <span className="text-sm">尚無照片</span>
    </div>
  )
}

function VehicleCard({ vehicle, showFloorPrice }: { vehicle: VehicleListItem; showFloorPrice: boolean }) {
  return (
    <Link
      to={`/vehicles/${vehicle.id}`}
      aria-label={`查看 ${vehicle.brand} ${vehicle.model} 詳情`}
      className="group min-w-0 overflow-hidden rounded-xl border border-border bg-surface shadow-sm transition-colors hover:border-border-strong hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
    >
      <VehicleCover vehicle={vehicle} />
      <div className="flex flex-col gap-4 p-4 sm:p-5">
        <div className="flex min-w-0 items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="truncate text-base font-semibold text-fg group-hover:text-primary">
              {vehicle.brand} {vehicle.model}
            </h2>
          </div>
          <VehicleStatusBadge status={vehicle.status} />
        </div>

        <dl className="grid grid-cols-3 gap-3 text-sm">
          <div className="min-w-0">
            <dt className="text-xs text-fg-muted">年份</dt>
            <dd className="mt-1 truncate text-fg">{vehicle.year ?? '—'}</dd>
          </div>
          <div className="min-w-0">
            <dt className="text-xs text-fg-muted">顏色</dt>
            <dd className="mt-1 truncate text-fg">{vehicle.color || '—'}</dd>
          </div>
          <div className="min-w-0">
            <dt className="text-xs text-fg-muted">車牌</dt>
            <dd className="mt-1 truncate text-fg">{vehicle.license_plate || '—'}</dd>
          </div>
        </dl>

        <dl className={`grid gap-3 border-t border-border pt-4 ${showFloorPrice ? 'grid-cols-2' : 'grid-cols-1'}`}>
          <div>
            <dt className="text-xs text-fg-muted">開價</dt>
            <dd className="mt-1 text-base font-semibold tabular-nums text-fg">{formatCurrency(vehicle.asking_price)}</dd>
          </div>
          {showFloorPrice && (
            <div className="text-right">
              <dt className="text-xs text-fg-muted">底價</dt>
              <dd className="mt-1 text-base font-semibold tabular-nums text-fg">{formatCurrency(vehicle.floor_price)}</dd>
            </div>
          )}
        </dl>
      </div>
    </Link>
  )
}

function VehicleCardSkeleton() {
  return (
    <div aria-hidden className="overflow-hidden rounded-xl border border-border bg-surface">
      <div className="aspect-[4/3] animate-pulse bg-surface-2" />
      <div className="space-y-4 p-4 sm:p-5">
        <div className="h-5 w-2/3 animate-pulse rounded bg-surface-2" />
        <div className="grid grid-cols-3 gap-3">
          <div className="h-9 animate-pulse rounded bg-surface-2" />
          <div className="h-9 animate-pulse rounded bg-surface-2" />
          <div className="h-9 animate-pulse rounded bg-surface-2" />
        </div>
        <div className="h-11 animate-pulse rounded bg-surface-2" />
      </div>
    </div>
  )
}

export function VehicleList() {
  const { user } = useAuth()
  const canManage = canManageVehicles(user?.role)
  const showFloorPrice = canViewFinancials(user?.role)
  const [searchParams, setSearchParams] = useSearchParams()
  const filters = parseVehicleListFilters(searchParams)
  const filterUrlKey = searchParams.toString()
  const statusKey = filters.statuses.join(',')
  const [vehicles, setVehicles] = useState<VehicleListItem[]>([])
  const [meta, setMeta] = useState<VehicleListMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [filterDrawerOpen, setFilterDrawerOpen] = useState(false)
  const [draftFilters, setDraftFilters] = useState<VehicleListFilters>(() => ({
    ...filters,
    statuses: [...filters.statuses],
  }))
  const filterDrawerTriggerRef = useRef<HTMLButtonElement>(null)

  function updateFilters(
    updates: Partial<typeof filters>,
    options: { resetPage?: boolean; replace?: boolean } = {},
  ) {
    setSearchParams(
      serializeVehicleListFilters({
        ...filters,
        ...updates,
        page: options.resetPage === false ? (updates.page ?? filters.page) : 1,
      }),
      { replace: options.replace ?? false },
    )
  }

  function clearFilters() {
    setSearchParams(
      serializeVehicleListFilters({
        search: '',
        statuses: [...defaultVehicleStatuses],
        soldMonth: '',
        page: 1,
      }),
    )
  }

  function openFilterDrawer() {
    setDraftFilters({ ...filters, statuses: [...filters.statuses] })
    setFilterDrawerOpen(true)
  }

  function applyDraftFilters() {
    setSearchParams(serializeVehicleListFilters({ ...draftFilters, page: 1 }))
    setFilterDrawerOpen(false)
  }

  function clearDraftFilters() {
    setDraftFilters({
      search: '',
      statuses: [...defaultVehicleStatuses],
      soldMonth: '',
      page: 1,
    })
  }

  useEffect(() => {
    if (filterDrawerOpen) return
    const urlFilters = parseVehicleListFilters(new URLSearchParams(filterUrlKey))
    setDraftFilters({ ...urlFilters, statuses: [...urlFilters.statuses] })
  }, [filterDrawerOpen, filterUrlKey])

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)
    setMeta(null)
    listVehicles({
      search: filters.search || undefined,
      status: statusKey.split(',') as VehicleStatus[],
      is_preparation_completed: filters.isPreparationCompleted,
      sold_month: filters.soldMonth || undefined,
      page: filters.page,
    })
      .then((response) => {
        if (!active) return
        setVehicles(response.data)
        setMeta(response.meta)
      })
      .catch((caught) => {
        if (active) setError(vehicleListError(caught))
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [filters.search, statusKey, filters.isPreparationCompleted, filters.soldMonth, filters.page])

  const hasActiveFilters = hasActiveVehicleListFilters(filters)
  const isPageOutOfRange = Boolean(meta && filters.page > meta.last_page)
  const activeFilterCount = Number(Boolean(filters.search)) +
    Number(!hasDefaultVehicleStatuses(filters.statuses)) +
    Number(filters.isPreparationCompleted !== undefined) +
    Number(Boolean(filters.soldMonth))

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">車輛管理</h1>
          <p className="mt-1 text-sm text-fg-muted">車輛庫存與銷售狀態總覽</p>
        </div>
        <div className="flex flex-wrap gap-3">
          {user?.role === 'admin' && (
            <Link
              to="/vehicles/commission-attribution"
              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
            >
              待補獎金歸屬
            </Link>
          )}
          {canManage && (
            <Link
              to="/vehicles/create"
              className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
            >
              新增買入車輛
            </Link>
          )}
        </div>
      </div>

      <div className="hidden sm:block">
        <VehicleFilterFields
          filters={draftFilters}
          debounceSearch
          onChange={(updates, options) => {
            setDraftFilters((current) => ({ ...current, ...updates, page: 1 }))
            updateFilters(updates, { replace: options?.replace })
          }}
        />
        {hasActiveFilters && (
          <button
            type="button"
            onClick={clearFilters}
            className="mt-3 min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            清除全部篩選條件
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3 sm:hidden">
        <button
          ref={filterDrawerTriggerRef}
          type="button"
          aria-expanded={filterDrawerOpen}
          aria-controls="vehicle-filter-drawer"
          onClick={openFilterDrawer}
          className="flex min-h-11 items-center gap-2 rounded-lg border border-border-strong bg-surface px-4 text-sm font-medium text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <SlidersHorizontal aria-hidden className="h-4 w-4" />
          篩選
          {activeFilterCount > 0 && <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-fg">{activeFilterCount}</span>}
        </button>
        {hasActiveFilters && (
          <button
            type="button"
            onClick={clearFilters}
            className="min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            清除全部
          </button>
        )}
      </div>

      {hasActiveFilters && (
        <div className="flex flex-wrap items-center gap-2" aria-label="已套用篩選條件">
          {filters.search && <ActiveFilterChip label={`搜尋：${filters.search}`} onRemove={() => updateFilters({ search: '' })} />}
          {!hasDefaultVehicleStatuses(filters.statuses) && (
            <ActiveFilterChip
              label={`狀態：${filters.statuses.map((status) => statusLabels[status]).join('、')}`}
              onRemove={() => updateFilters({ statuses: [...defaultVehicleStatuses] })}
            />
          )}
          {filters.isPreparationCompleted !== undefined && (
            <ActiveFilterChip
              label={`整備狀態：${filters.isPreparationCompleted ? '已完成' : '未完成'}`}
              onRemove={() => updateFilters({ isPreparationCompleted: undefined })}
            />
          )}
          {filters.soldMonth && (
            <ActiveFilterChip label={`成交月份：${formatSoldMonth(filters.soldMonth)}`} onRemove={() => updateFilters({ soldMonth: '' })} />
          )}
        </div>
      )}

      <MobileFilterDrawer
        id="vehicle-filter-drawer"
        title="篩選車輛"
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
        <VehicleFilterFields
          filters={draftFilters}
          onChange={(updates) => setDraftFilters((current) => ({ ...current, ...updates, page: 1 }))}
        />
      </MobileFilterDrawer>

      <div
        className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        aria-live="polite"
        aria-busy={loading}
      >
        {loading && Array.from({ length: 6 }, (_, index) => <VehicleCardSkeleton key={index} />)}

        {!loading && error && (
          <div role="alert" className="col-span-full rounded-xl border border-error/30 bg-surface p-6 text-center text-sm text-error">
            {error}
          </div>
        )}

        {!loading && !error && vehicles.length === 0 && (
          <div className="col-span-full flex min-h-48 flex-col items-center justify-center gap-3 rounded-xl border border-border bg-surface p-6 text-center text-fg-muted">
            <span>{isPageOutOfRange ? '此頁沒有資料' : hasActiveFilters ? '尚無符合條件的車輛' : '目前沒有在庫車輛'}</span>
            {isPageOutOfRange ? (
              <button
                type="button"
                onClick={() => updateFilters({ page: 1 }, { resetPage: false })}
                className="min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                回到第 1 頁
              </button>
            ) : hasActiveFilters ? (
              <button
                type="button"
                onClick={clearFilters}
                className="min-h-11 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                清除篩選條件
              </button>
            ) : (
              canManage && (
                <Link
                  to="/vehicles/create"
                  className="flex min-h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  新增第一輛車
                </Link>
              )
            )}
          </div>
        )}

        {!loading && !error && vehicles.map((vehicle) => (
          <VehicleCard key={vehicle.id} vehicle={vehicle} showFloorPrice={showFloorPrice} />
        ))}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex flex-col gap-3 text-sm text-fg-muted sm:flex-row sm:items-center sm:justify-between">
          <span>
            第 {meta.current_page} / {meta.last_page} 頁，共 {meta.total} 筆
          </span>
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <button
              type="button"
              onClick={() => updateFilters({ page: Math.max(1, filters.page - 1) }, { resetPage: false })}
              disabled={meta.current_page <= 1}
              className="min-h-11 rounded-lg border border-border-strong px-4 py-2 font-medium hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
            >
              上一頁
            </button>
            <button
              type="button"
              onClick={() => updateFilters({ page: Math.min(meta.last_page, filters.page + 1) }, { resetPage: false })}
              disabled={meta.current_page >= meta.last_page}
              className="min-h-11 rounded-lg border border-border-strong px-4 py-2 font-medium hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
            >
              下一頁
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
