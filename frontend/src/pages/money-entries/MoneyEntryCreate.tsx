import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { listCashAccountOptions } from '../../api/cashAccounts'
import { createMoneyEntry } from '../../api/moneyEntries'
import { listVehicleOptions } from '../../api/vehicles'
import type { CashAccountOption } from '../../types/cashAccount'
import type { CreateMoneyEntryPayload, MoneyDirection } from '../../types/moneyEntry'
import type { Vehicle } from '../../types/vehicle'
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

  const [fieldErrors, setFieldErrors] = useState<Partial<Record<'entryDate' | 'category' | 'cashAccountId' | 'amount', string>>>({})
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const idempotencyKeyRef = useRef<string | null>(null)

  useEffect(() => {
    listCashAccountOptions().then((accounts) => setCashAccounts(accounts.filter((a) => a.is_active))).catch(() => setCashAccounts([]))
    listVehicleOptions().then(setVehicles).catch(() => setVehicles([]))
  }, [])

  const categoryOptions = categoriesForDirection(direction)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    const nextFieldErrors: typeof fieldErrors = {}
    if (!entryDate) nextFieldErrors.entryDate = '請選擇日期'
    if (!category) nextFieldErrors.category = '請選擇分類'
    if (!cashAccountId) nextFieldErrors.cashAccountId = '請選擇資金帳戶'
    if (!amount || Number(amount) <= 0) nextFieldErrors.amount = '金額必須大於 0'
    setFieldErrors(nextFieldErrors)
    if (Object.keys(nextFieldErrors).length > 0) {
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
        <p className="mt-1 text-sm text-fg-muted">一般營運收支不綁車；單車收支必須選擇關聯車輛。</p>
      </div>

      <form noValidate onSubmit={handleSubmit} className="max-w-2xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        {error && <p className="mb-4 text-sm text-error">{error}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="money-entry-date" className="mb-1 block text-sm font-medium text-fg-muted">日期</label>
            <input
              id="money-entry-date"
              type="date"
              required
              value={entryDate}
              aria-invalid={Boolean(fieldErrors.entryDate)}
              aria-describedby={fieldErrors.entryDate ? 'money-entry-date-error' : undefined}
              onChange={(e) => {
                setEntryDate(e.target.value)
                setFieldErrors((current) => ({ ...current, entryDate: undefined }))
              }}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
            {fieldErrors.entryDate && <p id="money-entry-date-error" className="mt-1 text-xs text-error">{fieldErrors.entryDate}</p>}
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
            <label htmlFor="money-entry-category" className="mb-1 block text-sm font-medium text-fg-muted">分類</label>
            <select
              id="money-entry-category"
              required
              value={category}
              aria-invalid={Boolean(fieldErrors.category)}
              aria-describedby={fieldErrors.category ? 'money-entry-category-error' : undefined}
              onChange={(e) => {
                setCategory(e.target.value)
                setFieldErrors((current) => ({ ...current, category: undefined }))
              }}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            >
              <option value="">請選擇分類</option>
              {categoryOptions.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
            {fieldErrors.category && <p id="money-entry-category-error" className="mt-1 text-xs text-error">{fieldErrors.category}</p>}
          </div>

          <div>
            <label htmlFor="money-entry-amount" className="mb-1 block text-sm font-medium text-fg-muted">金額</label>
            <input
              id="money-entry-amount"
              type="number"
              required
              min={1}
              value={amount}
              aria-invalid={Boolean(fieldErrors.amount)}
              aria-describedby={fieldErrors.amount ? 'money-entry-amount-error' : undefined}
              onChange={(e) => {
                setAmount(e.target.value)
                setFieldErrors((current) => ({ ...current, amount: undefined }))
              }}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
            {fieldErrors.amount && <p id="money-entry-amount-error" className="mt-1 text-xs text-error">{fieldErrors.amount}</p>}
          </div>

          <div>
            <label htmlFor="money-entry-cash-account" className="mb-1 block text-sm font-medium text-fg-muted">資金帳戶</label>
            <select
              id="money-entry-cash-account"
              required
              value={cashAccountId}
              aria-invalid={Boolean(fieldErrors.cashAccountId)}
              aria-describedby={fieldErrors.cashAccountId ? 'money-entry-cash-account-error' : undefined}
              onChange={(e) => {
                setCashAccountId(e.target.value)
                setFieldErrors((current) => ({ ...current, cashAccountId: undefined }))
              }}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            >
              <option value="">請選擇資金帳戶</option>
              {cashAccounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
            {fieldErrors.cashAccountId && <p id="money-entry-cash-account-error" className="mt-1 text-xs text-error">{fieldErrors.cashAccountId}</p>}
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
