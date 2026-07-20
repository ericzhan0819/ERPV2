import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { listVehicles } from '../../api/vehicles'
import type { Vehicle, VehicleListMeta, VehicleStatus } from '../../types/vehicle'
import { VehicleStatusBadge } from '../../components/VehicleStatusBadge'
import { useAuth } from '../../hooks/useAuth'
import { canManageVehicles, canViewSalesPricing } from '../../utils/permissions'
import {
  defaultVehicleStatuses,
  parseVehicleListFilters,
  serializeVehicleListFilters,
  vehicleStatuses,
} from '../../utils/listFilters'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null | undefined): string {
  return amount === null || amount === undefined ? '-' : currencyFormatter.format(amount)
}

const statusLabels: Record<VehicleStatus, string> = {
  preparing: '整備中',
  listed: '上架中',
  reserved: '保留中',
  sold: '已售出',
  cancelled: '取消 / 退車',
}

export function VehicleList() {
  const { user } = useAuth()
  const canManage = canManageVehicles(user?.role)
  const canViewSalesPrice = canViewSalesPricing(user?.role)
  const columnCount = 7 + (canViewSalesPrice ? 2 : 0)
  const [searchParams, setSearchParams] = useSearchParams()
  const filters = parseVehicleListFilters(searchParams)
  const statusKey = filters.statuses.join(',')
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [meta, setMeta] = useState<VehicleListMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

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
        page: 1,
      }),
    )
  }

  function toggleStatus(status: VehicleStatus, checked: boolean) {
    const nextStatuses = checked
      ? vehicleStatuses.filter((candidate) => filters.statuses.includes(candidate) || candidate === status)
      : filters.statuses.filter((candidate) => candidate !== status)

    updateFilters(
      { statuses: nextStatuses.length > 0 ? nextStatuses : [...defaultVehicleStatuses] },
      { resetPage: true },
    )
  }

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)
    listVehicles({
      search: filters.search || undefined,
      status: statusKey.split(',') as VehicleStatus[],
      is_preparation_completed: filters.isPreparationCompleted,
      page: filters.page,
    })
      .then((response) => {
        if (!active) return
        setVehicles(response.data)
        setMeta(response.meta)
      })
      .catch(() => {
        if (active) setError('車輛列表載入失敗')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [filters.search, statusKey, filters.isPreparationCompleted, filters.page])

  const isDefaultStatusSet =
    filters.statuses.length === defaultVehicleStatuses.length &&
    defaultVehicleStatuses.every((status) => filters.statuses.includes(status))
  const hasActiveFilters = Boolean(filters.search || filters.isPreparationCompleted !== undefined || !isDefaultStatusSet)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">車輛管理</h1>
          <p className="mt-1 text-sm text-fg-muted">車輛庫存與銷售狀態總覽</p>
        </div>
        <div className="flex gap-3">
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

      <div className="flex flex-wrap items-end gap-4">
        <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
          搜尋車輛
          <input
            type="search"
            placeholder="庫存編號 / 廠牌 / 車型 / 車牌 / VIN"
            value={filters.search}
            onChange={(event) => updateFilters({ search: event.target.value }, { resetPage: true, replace: true })}
            className="w-72 rounded-lg border border-border-strong px-3 py-2 text-sm text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </label>

        <fieldset className="flex flex-wrap gap-x-3 gap-y-2">
          <legend className="mb-1 text-sm font-medium text-fg-muted">車輛狀態</legend>
          {vehicleStatuses.map((status) => (
            <label key={status} className="flex min-h-11 items-center gap-2 text-sm text-fg">
              <input
                type="checkbox"
                checked={filters.statuses.includes(status)}
                onChange={(event) => toggleStatus(status, event.target.checked)}
                className="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-ring/30"
              />
              {statusLabels[status]}
            </label>
          ))}
        </fieldset>

        <label className="flex flex-col gap-1 text-sm font-medium text-fg-muted">
          整備狀態
          <select
            value={filters.isPreparationCompleted === undefined ? '' : String(filters.isPreparationCompleted)}
            onChange={(event) => {
              const value = event.target.value
              updateFilters({
                isPreparationCompleted: value === '' ? undefined : value === 'true',
              })
            }}
            className="min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm text-fg focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          >
            <option value="">全部整備狀態</option>
            <option value="false">整備未完成</option>
            <option value="true">整備已完成</option>
          </select>
        </label>

        {hasActiveFilters && (
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
              <th className="px-4 py-3 text-left font-medium text-fg-muted">庫存編號</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">狀態</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">廠牌</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">車型</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">年式</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">車牌</th>
              {canViewSalesPrice && <th className="px-4 py-3 text-left font-medium text-fg-muted">開價</th>}
              {canViewSalesPrice && <th className="px-4 py-3 text-left font-medium text-fg-muted">成交價</th>}
              <th className="px-4 py-3 text-left font-medium text-fg-muted">建立日期</th>
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
            {!loading && vehicles.length === 0 && (
              <tr>
                <td colSpan={columnCount} className="px-4 py-6 text-center text-fg-muted">
                  <div className="flex flex-col items-center gap-2">
                    <span>{hasActiveFilters ? '尚無符合條件的車輛' : '目前沒有在庫車輛'}</span>
                    {hasActiveFilters ? (
                      <button type="button" onClick={clearFilters} className="text-sm font-medium text-primary hover:underline">
                        清除篩選條件
                      </button>
                    ) : (
                      canManage && (
                        <Link to="/vehicles/create" className="text-sm font-medium text-primary hover:underline">
                          新增第一輛車
                        </Link>
                      )
                    )}
                  </div>
                </td>
              </tr>
            )}
            {!loading &&
              vehicles.map((vehicle) => (
                <tr key={vehicle.id} className="hover:bg-surface-2">
                  <td className="px-4 py-3">
                    <Link to={`/vehicles/${vehicle.id}`} className="font-medium text-fg hover:underline">
                      {vehicle.stock_no}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <VehicleStatusBadge status={vehicle.status} />
                  </td>
                  <td className="px-4 py-3">{vehicle.brand}</td>
                  <td className="px-4 py-3">{vehicle.model}</td>
                  <td className="px-4 py-3">{vehicle.year ?? '-'}</td>
                  <td className="px-4 py-3">{vehicle.license_plate ?? '-'}</td>
                  {canViewSalesPrice && <td className="px-4 py-3 tabular-nums">{formatCurrency(vehicle.asking_price)}</td>}
                  {canViewSalesPrice && <td className="px-4 py-3 tabular-nums">{formatCurrency(vehicle.sold_price)}</td>}
                  <td className="px-4 py-3">{vehicle.created_at ? vehicle.created_at.slice(0, 10) : '-'}</td>
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
