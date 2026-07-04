import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { listCashAccounts } from '../../api/cashAccounts'
import { listMoneyEntries } from '../../api/moneyEntries'
import type { CashAccountOption } from '../../types/cashAccount'
import type { MoneyDirection, MoneyEntry, MoneyEntryListMeta } from '../../types/moneyEntry'
import type { Vehicle, VehicleListResponse } from '../../types/vehicle'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

export function MoneyEntryList() {
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
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    listCashAccounts().then(setCashAccounts).catch(() => setCashAccounts([]))
    apiClient
      .get<VehicleListResponse>('/api/vehicles', { params: { per_page: 100 } })
      .then((response) => setVehicles(response.data.data))
      .catch(() => setVehicles([]))
  }, [])

  useEffect(() => {
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
      page,
    })
      .then((response) => {
        setEntries(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('收支列表載入失敗'))
      .finally(() => setLoading(false))
  }, [search, direction, category, cashAccountId, vehicleId, dateFrom, dateTo, page])

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">收支管理</h1>
          <p className="mt-1 text-sm text-gray-500">一般營運與單車收支紀錄</p>
        </div>
        <div className="flex gap-3">
          <Link
            to="/money-entries/create?direction=income"
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
          >
            新增收入
          </Link>
          <Link
            to="/money-entries/create?direction=expense"
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
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
          className="w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
        />
        <select
          value={direction}
          onChange={(e) => {
            setPage(1)
            setDirection(e.target.value as MoneyDirection | '')
            setCategory('')
          }}
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
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
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
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
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
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
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
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
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
        />
        <input
          type="date"
          value={dateTo}
          onChange={(e) => {
            setPage(1)
            setDateTo(e.target.value)
          }}
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
        />
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-500">日期</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">收入/支出</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">分類</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">金額</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">資金帳戶</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">關聯車輛</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">對象</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">備註</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && (
              <tr>
                <td colSpan={8} className="px-4 py-6 text-center text-gray-500">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && entries.length === 0 && (
              <tr>
                <td colSpan={8} className="px-4 py-6 text-center text-gray-500">
                  尚無符合條件的收支紀錄
                </td>
              </tr>
            )}
            {!loading &&
              entries.map((entry) => (
                <tr key={entry.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">{entry.entry_date}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`rounded-full px-2 py-1 text-xs font-medium ${
                        entry.direction === 'income' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      }`}
                    >
                      {directionLabels[entry.direction]}
                    </span>
                  </td>
                  <td className="px-4 py-3">{entry.category}</td>
                  <td className="px-4 py-3">{currencyFormatter.format(entry.amount)}</td>
                  <td className="px-4 py-3">{entry.cash_account?.name ?? '-'}</td>
                  <td className="px-4 py-3">
                    {entry.vehicle ? (
                      <Link to={`/vehicles/${entry.vehicle.id}`} className="text-gray-900 hover:underline">
                        {entry.vehicle.stock_no}
                      </Link>
                    ) : (
                      '-'
                    )}
                  </td>
                  <td className="px-4 py-3">{entry.counterparty_name ?? '-'}</td>
                  <td className="px-4 py-3">{entry.description ?? '-'}</td>
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
