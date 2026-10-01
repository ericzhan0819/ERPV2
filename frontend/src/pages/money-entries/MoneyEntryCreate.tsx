import { extractFieldErrors } from '../../utils/fieldErrors'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { listCashAccountOptions } from '../../api/cashAccounts'
import { createMoneyEntry } from '../../api/moneyEntries'
import { VehicleSelect } from '../../components/VehicleSelect'
import type { CashAccountOption } from '../../types/cashAccount'
import type { CreateMoneyEntryPayload, MoneyDirection } from '../../types/moneyEntry'
import { generateIdempotencyKey } from '../../utils/idempotency'
import { formatBusinessDate } from '../../utils/dateTime'
import { categoriesForDirection, directionLabels } from '../../utils/moneyEntryCategory'
import { useAuth } from '../../hooks/useAuth'
import { canViewFinancials } from '../../utils/permissions'
import { FormAlert } from '../../components/FormAlert'

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
  const { user } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialDirection = searchParams.get('direction') === 'expense' ? 'expense' : 'income'

  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])

  const [entryDate, setEntryDate] = useState(formatBusinessDate())
  const [direction, setDirection] = useState<MoneyDirection>(initialDirection)
  const [category, setCategory] = useState('')
  const [amount, setAmount] = useState('')
  const [cashAccountId, setCashAccountId] = useState('')
  const [vehicleId, setVehicleId] = useState('')
  const [counterpartyName, setCounterpartyName] = useState('')
  const [description, setDescription] = useState('')

  const [fieldErrors, setFieldErrors] = useState<Record<string, string | undefined>>({})
  const [validationAttempt, setValidationAttempt] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const formRef = useRef<HTMLFormElement>(null)
  const idempotencyKeyRef = useRef<string | null>(null)

  useEffect(() => {
    listCashAccountOptions().then((accounts) => setCashAccounts(accounts.filter((a) => a.is_active))).catch(() => setCashAccounts([]))
  }, [])

  useEffect(() => {
    if (validationAttempt === 0) return
    const timeout = window.setTimeout(() => {
      formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    }, 100)
    return () => window.clearTimeout(timeout)
  }, [validationAttempt])

  const categoryOptions = categoriesForDirection(direction).filter((option) => option !== '購車付款' || canViewFinancials(user?.role))

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    const nextFieldErrors: typeof fieldErrors = {}
    if (!entryDate) nextFieldErrors.entryDate = '請選擇日期'
    if (!category) nextFieldErrors.category = '請選擇分類'
    if (!cashAccountId) nextFieldErrors.cashAccountId = '請選擇資金帳戶'
    if (!amount || Number(amount) <= 0) nextFieldErrors.amount = '金額必須大於 0'
    else if (Number(amount) > 999999999999) nextFieldErrors.amount = '金額不得超過 999,999,999,999 元'
    setFieldErrors(nextFieldErrors)
    if (Object.keys(nextFieldErrors).length > 0) {
      setValidationAttempt((current) => current + 1)
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
      const errors = extractFieldErrors(err)
      setFieldErrors({ ...errors, entryDate: errors.entry_date, cashAccountId: errors.cash_account_id })
      setValidationAttempt((current) => current + 1)
      if (Object.keys(errors).length === 0 || errors.idempotency_key) {
        setError(extractErrorMessage(err, '新增收支失敗，請稍後再試'))
      }

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

      <form ref={formRef} noValidate onSubmit={handleSubmit} className="max-w-2xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <FormAlert message={error} focusOnShow className="mb-4" />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="money-entry-date" className="mb-1 block text-sm font-medium text-fg-muted">日期 <span className="text-error">*</span></label>
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
            <label htmlFor="moneyentrycreate-field-1" className="mb-1 block text-sm font-medium text-fg-muted">收入 / 支出 <span className="text-error">*</span></label>
            <select
              id="moneyentrycreate-field-1"
              aria-invalid={Boolean(fieldErrors.direction)}
              aria-describedby={fieldErrors.direction ? "moneyentrycreate-field-1-error" : undefined}
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
          {fieldErrors.direction && <p id="moneyentrycreate-field-1-error" className="text-sm text-error">{fieldErrors.direction}</p>}
          </div>

          <div>
            <label htmlFor="money-entry-category" className="mb-1 block text-sm font-medium text-fg-muted">分類 <span className="text-error">*</span></label>
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
            <label htmlFor="money-entry-amount" className="mb-1 block text-sm font-medium text-fg-muted">金額 <span className="text-error">*</span></label>
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
            <label htmlFor="money-entry-cash-account" className="mb-1 block text-sm font-medium text-fg-muted">資金帳戶 <span className="text-error">*</span></label>
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

          <VehicleSelect error={fieldErrors.vehicle_id} value={vehicleId ? Number(vehicleId) : null} onChange={(value) => setVehicleId(value === null ? '' : String(value))} inventoryOnly label="關聯車輛（可空白）" emptyLabel="不綁定車輛" />

          <div>
            <label htmlFor="moneyentrycreate-field-2" className="mb-1 block text-sm font-medium text-fg-muted">對象</label>
            <input
              id="moneyentrycreate-field-2"
              aria-invalid={Boolean(fieldErrors.counterparty_name)}
              aria-describedby={fieldErrors.counterparty_name ? "moneyentrycreate-field-2-error" : undefined}
              type="text"
              value={counterpartyName}
              onChange={(e) => setCounterpartyName(e.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            />
            {fieldErrors.counterparty_name && <p id="moneyentrycreate-field-2-error" className="text-sm text-error">{fieldErrors.counterparty_name}</p>}
          </div>
        </div>

        <div className="mt-4">
          <label htmlFor="moneyentrycreate-field-3" className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea
            id="moneyentrycreate-field-3"
            aria-invalid={Boolean(fieldErrors.description)}
            aria-describedby={fieldErrors.description ? "moneyentrycreate-field-3-error" : undefined}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
          {fieldErrors.description && <p id="moneyentrycreate-field-3-error" className="text-sm text-error">{fieldErrors.description}</p>}
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
