import { useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { closeSaleVehicle, getVehicle, listVehicleForSale, recordFinalPayment, reserveVehicle } from '../../api/vehicles'
import { listCashAccountOptions } from '../../api/cashAccounts'
import type { VehicleDetailResponse } from '../../types/vehicle'
import type { CashAccountOption } from '../../types/cashAccount'
import { generateIdempotencyKey } from '../../utils/idempotency'
import { vehicleStatusLabels } from '../../utils/vehicleStatus'
import { VehicleStatusBadge } from '../../components/VehicleStatusBadge'
import { CustomerSelect } from '../../components/CustomerSelect'
import { useAuth } from '../../hooks/useAuth'
import { canManageVehicles, canRunSalesFlow, canViewFinancials, canViewSalesPricing } from '../../utils/permissions'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null | undefined): string {
  return amount === null || amount === undefined ? '-' : currencyFormatter.format(amount)
}

interface InfoRowProps {
  label: string
  value: string
}

function InfoRow({ label, value }: InfoRowProps) {
  return (
    <div className="flex justify-between border-b border-border py-2 text-sm last:border-0">
      <span className="text-fg-muted">{label}</span>
      <span className="font-medium text-fg tabular-nums">{value}</span>
    </div>
  )
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <h2 className="mb-2 text-sm font-semibold text-fg-muted">{title}</h2>
      {children}
    </div>
  )
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-surface p-6 shadow-lg">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-base font-semibold text-fg">{title}</h3>
          <button onClick={onClose} className="text-sm text-fg-subtle hover:text-fg-muted">
            關閉
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

function Field({
  label,
  value,
  onChange,
  type = 'text',
  required,
  readOnly,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  type?: string
  required?: boolean
  readOnly?: boolean
}) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-fg-muted">
        {label}
        {required && <span className="text-error"> *</span>}
      </label>
      <input
        type={type}
        required={required}
        readOnly={readOnly}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30 read-only:bg-surface-2 read-only:text-fg-muted"
      />
    </div>
  )
}

function CashAccountField({
  cashAccounts,
  value,
  onChange,
}: {
  cashAccounts: CashAccountOption[]
  value: string
  onChange: (value: string) => void
}) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-fg-muted">
        收款帳戶
        <span className="text-error"> *</span>
      </label>
      <select
        required
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
      >
        <option value="">請選擇</option>
        {cashAccounts
          .filter((account) => account.is_active)
          .map((account) => (
            <option key={account.id} value={account.id}>
              {account.name}
            </option>
          ))}
      </select>
    </div>
  )
}

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

type ActiveModal = 'list' | 'reserve' | 'final-payment' | 'close-sale' | null

export function VehicleDetail() {
  const { user } = useAuth()
  const canViewFinance = canViewFinancials(user?.role)
  const canViewSalesPrice = canViewSalesPricing(user?.role)
  const canManage = canManageVehicles(user?.role)
  const canSell = canRunSalesFlow(user?.role)
  const { id } = useParams<{ id: string }>()
  const vehicleId = Number(id)
  const [detail, setDetail] = useState<VehicleDetailResponse | null>(null)
  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [error, setError] = useState<string | null>(null)
  const [activeModal, setActiveModal] = useState<ActiveModal>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [warning, setWarning] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function loadDetail() {
    getVehicle(vehicleId)
      .then(setDetail)
      .catch(() => setError('車輛資料載入失敗'))
  }

  useEffect(() => {
    if (!id) return
    loadDetail()
    listCashAccountOptions()
      .then(setCashAccounts)
      .catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (error) {
    return <p className="text-sm text-error">{error}</p>
  }

  if (!detail) {
    return <p className="text-sm text-fg-muted">載入中...</p>
  }

  const { vehicle, summary, money_entries: moneyEntries } = detail

  function closeModal() {
    setActiveModal(null)
    setFormError(null)
  }

  async function handleList(form: { asking_price: string; floor_price: string; listing_date: string; sales_note: string }) {
    setSubmitting(true)
    setFormError(null)
    try {
      await listVehicleForSale(vehicleId, {
        asking_price: Number(form.asking_price),
        floor_price: form.floor_price ? Number(form.floor_price) : undefined,
        listing_date: form.listing_date || undefined,
        sales_note: form.sales_note || undefined,
      })
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '上架失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleReserve(form: {
    buyer_name: string
    buyer_phone: string
    buyer_customer_id: string
    sold_price: string
    deposit_amount: string
    cash_account_id: string
    description: string
    idempotency_key: string
  }) {
    setSubmitting(true)
    setFormError(null)
    try {
      await reserveVehicle(vehicleId, {
        buyer_name: form.buyer_name,
        buyer_phone: form.buyer_phone || undefined,
        buyer_customer_id: form.buyer_customer_id ? Number(form.buyer_customer_id) : undefined,
        sold_price: Number(form.sold_price),
        deposit_amount: Number(form.deposit_amount),
        cash_account_id: Number(form.cash_account_id),
        description: form.description || undefined,
        idempotency_key: form.idempotency_key,
      })
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '收訂金並保留失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleFinalPayment(form: { amount: string; cash_account_id: string; description: string; idempotency_key: string }) {
    setSubmitting(true)
    setFormError(null)
    try {
      const result = await recordFinalPayment(vehicleId, {
        amount: Number(form.amount),
        cash_account_id: Number(form.cash_account_id),
        idempotency_key: form.idempotency_key,
        description: form.description || undefined,
      })
      setWarning(result.warning)
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '收尾款失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleCloseSale(form: { sold_at: string }) {
    setSubmitting(true)
    setFormError(null)
    try {
      await closeSaleVehicle(vehicleId, { sold_at: form.sold_at || undefined })
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '成交結案失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-semibold text-fg">{vehicle.stock_no}</h1>
            <VehicleStatusBadge status={vehicle.status} />
          </div>
          <p className="mt-1 text-sm text-fg-muted">
            {vehicle.brand} {vehicle.model}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {canManage && (
            <Link
              to={`/vehicles/${vehicleId}/print/intake`}
              target="_blank"
              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
            >
              列印建檔資料
            </Link>
          )}
          {canManage && vehicle.status === 'sold' && (
            <Link
              to={`/vehicles/${vehicleId}/print/closing`}
              target="_blank"
              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
            >
              列印成交結案明細
            </Link>
          )}
          <Link
            to="/vehicles"
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            返回列表
          </Link>
        </div>
      </div>

      {warning && (
        <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-fg">{warning}</div>
      )}

      <div className="flex flex-wrap gap-3">
        {canManage && vehicle.status === 'preparing' && (
          <button
            onClick={() => setActiveModal('list')}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
          >
            整備完成並上架
          </button>
        )}
        {canSell && vehicle.status === 'listed' && (
          <button
            onClick={() => setActiveModal('reserve')}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
          >
            收訂金並保留
          </button>
        )}
        {canSell && vehicle.status === 'reserved' && (
          <>
            <button
              onClick={() => setActiveModal('final-payment')}
              className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
            >
              收尾款
            </button>
            <button
              onClick={() => setActiveModal('close-sale')}
              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
            >
              成交結案
            </button>
          </>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Panel title="基本資料">
          <InfoRow label="庫存編號" value={vehicle.stock_no} />
          <InfoRow label="狀態" value={vehicleStatusLabels[vehicle.status]} />
          <InfoRow label="廠牌" value={vehicle.brand} />
          <InfoRow label="車型" value={vehicle.model} />
          <InfoRow label="年式" value={vehicle.year?.toString() ?? '-'} />
          <InfoRow label="車牌" value={vehicle.license_plate ?? '-'} />
          <InfoRow label="VIN" value={vehicle.vin ?? '-'} />
          <InfoRow label="里程" value={vehicle.mileage_km ? `${vehicle.mileage_km} km` : '-'} />
          <InfoRow label="顏色" value={vehicle.color ?? '-'} />
          <InfoRow label="排氣量" value={vehicle.displacement ?? '-'} />
          <InfoRow label="變速系統" value={vehicle.transmission ?? '-'} />
          <InfoRow label="燃料" value={vehicle.fuel_type ?? '-'} />
          <InfoRow label="停放位置" value={vehicle.parking_location ?? '-'} />
          <InfoRow label="備註" value={vehicle.notes ?? '-'} />
        </Panel>

        <Panel title="入庫檢核">
          <InfoRow label="行照" value={vehicle.has_registration_document ? '有' : '無'} />
          <InfoRow label="鑰匙 / 備用鑰匙" value={vehicle.has_spare_key ? '有' : '無'} />
          <InfoRow label="過戶" value={vehicle.is_transfer_completed ? '已完成' : '未完成'} />
          <InfoRow label="驗車" value={vehicle.is_inspection_completed ? '已完成' : '未完成'} />
          <InfoRow label="整備" value={vehicle.is_preparation_completed ? '已完成' : '未完成'} />
          <InfoRow label="貸款 / 權利問題備註" value={vehicle.lien_note ?? '-'} />
          <InfoRow label="車況備註" value={vehicle.condition_note ?? '-'} />
        </Panel>

        <Panel title="採購資料">
          <InfoRow label="買入日期" value={vehicle.purchase_date ?? '-'} />
          <InfoRow label="買入來源" value={vehicle.purchase_source_type ?? '-'} />
          <InfoRow label="原車主 / 供應商" value={vehicle.seller_name ?? '-'} />
          {canViewFinance && <InfoRow label="收購價" value={formatCurrency(vehicle.purchase_price)} />}
        </Panel>

        <Panel title="銷售資料">
          {canViewSalesPrice && <InfoRow label="開價" value={formatCurrency(vehicle.asking_price)} />}
          {canViewSalesPrice && <InfoRow label="底價" value={formatCurrency(vehicle.floor_price)} />}
          {canViewFinance && <InfoRow label="成交價" value={formatCurrency(vehicle.sold_price)} />}
          <InfoRow label="買方姓名" value={vehicle.buyer_name ?? '-'} />
          <InfoRow label="買方電話" value={vehicle.buyer_phone ?? '-'} />
          <InfoRow label="成交日期" value={vehicle.sold_at ? vehicle.sold_at.slice(0, 10) : '-'} />
        </Panel>
      </div>

      {summary && (
        <Panel title="單車收支摘要">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">單車收入合計</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">{formatCurrency(summary.income_total)}</p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">單車支出合計</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">{formatCurrency(summary.expense_total)}</p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">單車毛利</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">{formatCurrency(summary.gross_profit)}</p>
            </div>
          </div>
        </Panel>
      )}

      <Panel title="單車收支明細">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">日期</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">收支</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">分類</th>
                {canViewFinance && <th className="px-3 py-2 text-left font-medium text-fg-muted">金額</th>}
                {canViewFinance && <th className="px-3 py-2 text-left font-medium text-fg-muted">資金帳戶</th>}
                <th className="px-3 py-2 text-left font-medium text-fg-muted">說明</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {moneyEntries.length === 0 && (
                <tr>
                  <td colSpan={canViewFinance ? 6 : 4} className="px-3 py-4 text-center text-fg-muted">
                    尚無收支紀錄
                  </td>
                </tr>
              )}
              {moneyEntries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-3 py-2">{entry.entry_date}</td>
                  <td className="px-3 py-2">{entry.direction === 'income' ? '收入' : '支出'}</td>
                  <td className="px-3 py-2">{entry.category}</td>
                  {canViewFinance && <td className="px-3 py-2 tabular-nums">{formatCurrency(entry.amount)}</td>}
                  {canViewFinance && <td className="px-3 py-2">{entry.cash_account?.name ?? '-'}</td>}
                  <td className="px-3 py-2">{entry.description ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      {activeModal === 'list' && (
        <ListModal onClose={closeModal} onSubmit={handleList} error={formError} submitting={submitting} />
      )}
      {activeModal === 'reserve' && (
        <ReserveModal
          onClose={closeModal}
          onSubmit={handleReserve}
          error={formError}
          submitting={submitting}
          cashAccounts={cashAccounts}
        />
      )}
      {activeModal === 'final-payment' && (
        <FinalPaymentModal
          onClose={closeModal}
          onSubmit={handleFinalPayment}
          error={formError}
          submitting={submitting}
          cashAccounts={cashAccounts}
        />
      )}
      {activeModal === 'close-sale' && (
        <CloseSaleModal onClose={closeModal} onSubmit={handleCloseSale} error={formError} submitting={submitting} />
      )}
    </div>
  )
}

function ListModal({
  onClose,
  onSubmit,
  error,
  submitting,
}: {
  onClose: () => void
  onSubmit: (form: { asking_price: string; floor_price: string; listing_date: string; sales_note: string }) => void
  error: string | null
  submitting: boolean
}) {
  const [asking_price, setAskingPrice] = useState('')
  const [floor_price, setFloorPrice] = useState('')
  const [listing_date, setListingDate] = useState('')
  const [sales_note, setSalesNote] = useState('')

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ asking_price, floor_price, listing_date, sales_note })
  }

  return (
    <Modal title="整備完成並上架" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="開價" value={asking_price} onChange={setAskingPrice} type="number" required />
        <Field label="底價" value={floor_price} onChange={setFloorPrice} type="number" />
        <Field label="上架日期" value={listing_date} onChange={setListingDate} type="date" />
        <div>
          <label className="mb-1 block text-sm font-medium text-fg-muted">銷售備註</label>
          <textarea
            value={sales_note}
            onChange={(e) => setSalesNote(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </div>
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '處理中...' : '確認上架'}
        </button>
      </form>
    </Modal>
  )
}

function ReserveModal({
  onClose,
  onSubmit,
  error,
  submitting,
  cashAccounts,
}: {
  onClose: () => void
  onSubmit: (form: {
    buyer_name: string
    buyer_phone: string
    buyer_customer_id: string
    sold_price: string
    deposit_amount: string
    cash_account_id: string
    description: string
    idempotency_key: string
  }) => void
  error: string | null
  submitting: boolean
  cashAccounts: CashAccountOption[]
}) {
  const [buyer_name, setBuyerName] = useState('')
  const [buyer_phone, setBuyerPhone] = useState('')
  const [buyer_customer_id, setBuyerCustomerId] = useState('')
  const [buyerCustomerLabel, setBuyerCustomerLabel] = useState('')
  const [sold_price, setSoldPrice] = useState('')
  const [deposit_amount, setDepositAmount] = useState('')
  const [cash_account_id, setCashAccountId] = useState('')
  const [description, setDescription] = useState('')
  const idempotencyKeyRef = useRef<string | null>(null)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    let idempotencyKey = idempotencyKeyRef.current
    if (!idempotencyKey) {
      idempotencyKey = generateIdempotencyKey()
      idempotencyKeyRef.current = idempotencyKey
    }
    onSubmit({
      buyer_name,
      buyer_phone,
      buyer_customer_id,
      sold_price,
      deposit_amount,
      cash_account_id,
      description,
      idempotency_key: idempotencyKey,
    })
  }

  return (
    <Modal title="收訂金並保留" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <CustomerSelect
          label="關聯客戶（買方）"
          value={buyer_customer_id}
          selectedLabel={buyerCustomerLabel}
          onChange={(customerId, customer) => {
            setBuyerCustomerId(customerId)
            if (customer) {
              setBuyerName(customer.name)
              setBuyerPhone(customer.phone ?? '')
              setBuyerCustomerLabel(customer.name)
            } else {
              setBuyerCustomerLabel('')
            }
          }}
        />
        <Field label="買方姓名" value={buyer_name} onChange={setBuyerName} required readOnly={!!buyer_customer_id} />
        <Field label="買方電話" value={buyer_phone} onChange={setBuyerPhone} readOnly={!!buyer_customer_id} />
        <Field label="成交價" value={sold_price} onChange={setSoldPrice} type="number" required />
        <Field label="訂金金額" value={deposit_amount} onChange={setDepositAmount} type="number" required />
        <CashAccountField cashAccounts={cashAccounts} value={cash_account_id} onChange={setCashAccountId} />
        <div>
          <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </div>
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '處理中...' : '確認保留'}
        </button>
      </form>
    </Modal>
  )
}

function FinalPaymentModal({
  onClose,
  onSubmit,
  error,
  submitting,
  cashAccounts,
}: {
  onClose: () => void
  onSubmit: (form: { amount: string; cash_account_id: string; description: string; idempotency_key: string }) => void
  error: string | null
  submitting: boolean
  cashAccounts: CashAccountOption[]
}) {
  const [amount, setAmount] = useState('')
  const [cash_account_id, setCashAccountId] = useState('')
  const [description, setDescription] = useState('')
  const idempotencyKeyRef = useRef<string | null>(null)

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    let idempotencyKey = idempotencyKeyRef.current
    if (!idempotencyKey) {
      idempotencyKey = generateIdempotencyKey()
      idempotencyKeyRef.current = idempotencyKey
    }
    onSubmit({ amount, cash_account_id, description, idempotency_key: idempotencyKey })
  }

  return (
    <Modal title="收尾款" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="尾款金額" value={amount} onChange={setAmount} type="number" required />
        <CashAccountField cashAccounts={cashAccounts} value={cash_account_id} onChange={setCashAccountId} />
        <div>
          <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </div>
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '處理中...' : '確認收款'}
        </button>
      </form>
    </Modal>
  )
}

function CloseSaleModal({
  onClose,
  onSubmit,
  error,
  submitting,
}: {
  onClose: () => void
  onSubmit: (form: { sold_at: string }) => void
  error: string | null
  submitting: boolean
}) {
  const [sold_at, setSoldAt] = useState('')

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ sold_at })
  }

  return (
    <Modal title="成交結案" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Field label="成交日期（預設今天）" value={sold_at} onChange={setSoldAt} type="date" />
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '處理中...' : '確認結案'}
        </button>
      </form>
    </Modal>
  )
}
