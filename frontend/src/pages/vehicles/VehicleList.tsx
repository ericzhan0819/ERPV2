import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listVehicles } from '../../api/vehicles'
import type { Vehicle, VehicleListMeta, VehicleStatus } from '../../types/vehicle'
import { vehicleStatusBadgeClasses, vehicleStatusLabels } from '../../utils/vehicleStatus'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null): string {
  return amount === null ? '-' : currencyFormatter.format(amount)
}

const statusOptions: { value: VehicleStatus | ''; label: string }[] = [
  { value: '', label: '全部狀態' },
  { value: 'preparing', label: '整備中' },
  { value: 'listed', label: '上架中' },
  { value: 'reserved', label: '保留中' },
  { value: 'sold', label: '已售出' },
  { value: 'cancelled', label: '取消 / 退車' },
]

export function VehicleList() {
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [meta, setMeta] = useState<VehicleListMeta | null>(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<VehicleStatus | ''>('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setLoading(true)
    setError(null)
    listVehicles({ search: search || undefined, status: status || undefined, page })
      .then((response) => {
        setVehicles(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('車輛列表載入失敗'))
      .finally(() => setLoading(false))
  }, [search, status, page])

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">車輛管理</h1>
          <p className="mt-1 text-sm text-gray-500">車輛庫存與銷售狀態總覽</p>
        </div>
        <Link
          to="/vehicles/create"
          className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
          新增買入車輛
        </Link>
      </div>

      <div className="flex flex-wrap gap-3">
        <input
          type="text"
          placeholder="搜尋庫存編號 / 廠牌 / 車型 / 車牌 / VIN"
          value={search}
          onChange={(e) => {
            setPage(1)
            setSearch(e.target.value)
          }}
          className="w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
        />
        <select
          value={status}
          onChange={(e) => {
            setPage(1)
            setStatus(e.target.value as VehicleStatus | '')
          }}
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
        >
          {statusOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-500">庫存編號</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">狀態</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">廠牌</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">車型</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">年式</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">車牌</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">開價</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">成交價</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">建立日期</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && (
              <tr>
                <td colSpan={9} className="px-4 py-6 text-center text-gray-500">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && vehicles.length === 0 && (
              <tr>
                <td colSpan={9} className="px-4 py-6 text-center text-gray-500">
                  尚無符合條件的車輛
                </td>
              </tr>
            )}
            {!loading &&
              vehicles.map((vehicle) => (
                <tr key={vehicle.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <Link to={`/vehicles/${vehicle.id}`} className="font-medium text-gray-900 hover:underline">
                      {vehicle.stock_no}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${vehicleStatusBadgeClasses[vehicle.status]}`}>
                      {vehicleStatusLabels[vehicle.status]}
                    </span>
                  </td>
                  <td className="px-4 py-3">{vehicle.brand}</td>
                  <td className="px-4 py-3">{vehicle.model}</td>
                  <td className="px-4 py-3">{vehicle.year ?? '-'}</td>
                  <td className="px-4 py-3">{vehicle.license_plate ?? '-'}</td>
                  <td className="px-4 py-3">{formatCurrency(vehicle.asking_price)}</td>
                  <td className="px-4 py-3">{formatCurrency(vehicle.sold_price)}</td>
                  <td className="px-4 py-3">{vehicle.created_at ? vehicle.created_at.slice(0, 10) : '-'}</td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-600">
          <span>
            第 {meta.current_page} / {meta.last_page} 頁，共 {meta.total} 筆
          </span>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={meta.current_page <= 1}
              className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-50"
            >
              上一頁
            </button>
            <button
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={meta.current_page >= meta.last_page}
              className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-50"
            >
              下一頁
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
