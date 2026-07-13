import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { apiClient } from '../../api/client'
import { listCashAccountOptions } from '../../api/cashAccounts'
import { createMoneyEntry } from '../../api/moneyEntries'
import type { CashAccountOption } from '../../types/cashAccount'
import type { CreateMoneyEntryPayload, MoneyDirection } from '../../types/moneyEntry'
import type { Vehicle, VehicleListResponse } from '../../types/vehicle'
import { generateIdempotencyKey } from '../../utils/idempotency'
import { formatBusinessDate } from '../../utils/dateTime'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'

function extractErrorMessage(err: unknown, fallback: string): string {
  if (isAxiosError(err)) {
    const data = err.response?.data
    if (data?.errors) {
      const firstError = Object.values(data.errors)[0]
      if (Array.isArray(firstError) && firstError.length > 0) return firstError[0]
    }
    if (data?.message) return data.message
  }
  return fallback
}

export function MoneyEntryCreate() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialDirection = searchParams.get('direction') === 'expense' ? 'expense' : 'income'

  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [vehicles, setVehicles] = useState<Vehicle[]>([])

  const [entryDate, setEntryDate] = useState(formatBusinessDate())
  const [direction, setDirection] = useState<MoneyDirection>(initialDirection)
  const [category, setCategory] = useState('')
  const [amount, setAmount] = useState('')
  const [cashAccountId, setCashAccountId] = useState('')
  const [vehicleId, setVehicleId] = useState('')
  const [counterpartyName, setCounterpartyName] = useState('')
  const [description, setDescription] = useState('')

  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const idempotencyKeyRef = useRef<string | null>(null)

  useEffect(() => {
    listCashAccountOptions().then((accounts) => setCashAccounts(accounts.filter((a) => a.is_active))).catch(() => setCashAccounts([]))
    apiClient
      .get<VehicleListResponse>('/api/vehicles', { params: { per_page: 100 } })
      .then((response) => setVehicles(response.data.data))
      .catch(() => setVehicles([]))
  }, [])

  const categoryOptions = categoriesForDirection(direction)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (!entryDate) {
      setError('請選擇日期')
      return
    }
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

    if (!idempotencyKeyRef.current) {
      idempotencyKeyRef.current = generateIdempotencyKey()
    }

    const payload: CreateMoneyEntryPayload = {
      entry_date: entryDate,
      direction,
      category,
      amount: Number(amount),
      cash_account_id: Number(cashAccountId),
      idempotency_key: idempotencyKeyRef.current,
    }
    if (vehicleId) payload.vehicle_id = Number(vehicleId)
    if (counterpartyName) payload.counterparty_name = counterpartyName
    if (description) payload.description = description

    setSubmitting(true)
    try {
      await createMoneyEntry(payload)
      navigate('/money-entries')
    } catch (err) {
      setError(extractErrorMessage(err, '新增收支失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-fg">新增收支</h1>
        <p className="mt-1 text-sm text-fg-muted">一般營運收支請勿選擇車輛；單車相關收支請務必綁定車輛</p>
      </div>

      <form noValidate onSubmit={handleSubmit} className="max-w-2xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">日期</label>
            <input
              type="date"
              required
              value={entryDate}
              onChange={(e) => setEntryDate(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">收入 / 支出</label>
            <select
              value={direction}
              onChange={(e) => {
                setDirection(e.target.value as MoneyDirection)
                setCategory('')
              }}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            >
              <option value="income">{directionLabels.income}</option>
              <option value="expense">{directionLabels.expense}</option>
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">分類</label>
            <select
              required
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
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
            <label className="mb-1 block text-sm font-medium text-fg-muted">金額</label>
            <input
              type="number"
              required
              min={1}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">資金帳戶</label>
            <select
              required
              value={cashAccountId}
              onChange={(e) => setCashAccountId(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
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
            <label className="mb-1 block text-sm font-medium text-fg-muted">關聯車輛（可空白）</label>
            <select
              value={vehicleId}
              onChange={(e) => setVehicleId(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
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
            <label className="mb-1 block text-sm font-medium text-fg-muted">對象</label>
            <input
              type="text"
              value={counterpartyName}
              onChange={(e) => setCounterpartyName(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
          </div>
        </div>

        <div className="mt-4">
          <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </div>

        {error && <p className="mt-4 text-sm text-error">{error}</p>}

        <div className="mt-6 flex gap-3">
          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
          >
            {submitting ? '建立中...' : '建立收支'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/money-entries')}
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            取消
          </button>
        </div>
      </form>
    </div>
  )
}
