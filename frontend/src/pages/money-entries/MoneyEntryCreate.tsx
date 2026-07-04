import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { listCashAccounts } from '../../api/cashAccounts'
import { createMoneyEntry } from '../../api/moneyEntries'
import type { CashAccountOption } from '../../types/cashAccount'
import type { CreateMoneyEntryPayload, MoneyDirection } from '../../types/moneyEntry'
import type { Vehicle, VehicleListResponse } from '../../types/vehicle'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export function MoneyEntryCreate() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialDirection = searchParams.get('direction') === 'expense' ? 'expense' : 'income'

  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])

  const [entryDate, setEntryDate] = useState(today())
  const [direction, setDirection] = useState<MoneyDirection>(initialDirection)
  const [category, setCategory] = useState('')
  const [amount, setAmount] = useState('')
  const [cashAccountId, setCashAccountId] = useState('')
  const [vehicleId, setVehicleId] = useState('')
  const [counterpartyName, setCounterpartyName] = useState('')
  const [description, setDescription] = useState('')

  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    listCashAccounts().then((accounts) => setCashAccounts(accounts.filter((a) => a.is_active))).catch(() => setCashAccounts([]))
    apiClient
      .get<VehicleListResponse>('/api/vehicles', { params: { per_page: 100 } })
      .then((response) => setVehicles(response.data.data))
      .catch(() => setVehicles([]))
  }, [])

  const categoryOptions = categoriesForDirection(direction)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (!category) {
      setError('請選擇分類')
      return
    }
    if (!cashAccountId) {
      setError('請選擇資金帳戶')
      return
    }
    if (!amount || Number(amount) <= 0) {
      setError('金額必須大於 0')
      return
    }

    const payload: CreateMoneyEntryPayload = {
      entry_date: entryDate,
      direction,
      category,
      amount: Number(amount),
      cash_account_id: Number(cashAccountId),
    }
    if (vehicleId) payload.vehicle_id = Number(vehicleId)
    if (counterpartyName) payload.counterparty_name = counterpartyName
    if (description) payload.description = description

    setSubmitting(true)
    try {
      await createMoneyEntry(payload)
      navigate('/money-entries')
    } catch (err) {
      if (isAxiosError(err) && err.response?.data?.message) {
        setError(err.response.data.message)
      } else {
        setError('新增收支失敗，請稍後再試')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">新增收支</h1>
        <p className="mt-1 text-sm text-gray-500">一般營運收支請勿選擇車輛；單車相關收支請務必綁定車輛</p>
      </div>

      <form onSubmit={handleSubmit} className="max-w-2xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">日期</label>
            <input
              type="date"
              required
              value={entryDate}
              onChange={(e) => setEntryDate(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">收入 / 支出</label>
            <select
              value={direction}
              onChange={(e) => {
                setDirection(e.target.value as MoneyDirection)
                setCategory('')
              }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            >
              <option value="income">{directionLabels.income}</option>
              <option value="expense">{directionLabels.expense}</option>
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">分類</label>
            <select
              required
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            >
              <option value="">請選擇分類</option>
              {categoryOptions.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">金額</label>
            <input
              type="number"
              required
              min={1}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">資金帳戶</label>
            <select
              required
              value={cashAccountId}
              onChange={(e) => setCashAccountId(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            >
              <option value="">請選擇資金帳戶</option>
              {cashAccounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">關聯車輛（可空白）</label>
            <select
              value={vehicleId}
              onChange={(e) => setVehicleId(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            >
              <option value="">不綁定車輛</option>
              {vehicles.map((vehicle) => (
                <option key={vehicle.id} value={vehicle.id}>
                  {vehicle.stock_no}（{vehicle.brand} {vehicle.model}）
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">對象</label>
            <input
              type="text"
              value={counterpartyName}
              onChange={(e) => setCounterpartyName(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
            />
          </div>
        </div>

        <div className="mt-4">
          <label className="mb-1 block text-sm font-medium text-gray-700">備註</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
          />
        </div>

        {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

        <div className="mt-6 flex gap-3">
          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            {submitting ? '建立中...' : '建立收支'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/money-entries')}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
          >
            取消
          </button>
        </div>
      </form>
    </div>
  )
}
