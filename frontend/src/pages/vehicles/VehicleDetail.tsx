import { useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import {
  closeSaleVehicle,
  getVehicle,
  listCommissionAgentOptions,
  listVehicleForSale,
  recordFinalPayment,
  recordVehicleExpense,
  reserveVehicle,
  updateVehiclePurchasePrice,
} from '../../api/vehicles'
import { listCashAccountOptions } from '../../api/cashAccounts'
import {
  deleteVehiclePhoto,
  listVehiclePhotos,
  reorderVehiclePhotos,
  setCoverVehiclePhoto,
  uploadVehiclePhotos,
} from '../../api/vehiclePhotos'
import type { CommissionAgent, VehicleDetailResponse, VehiclePhoto } from '../../types/vehicle'
import type { CashAccountOption } from '../../types/cashAccount'
import { generateIdempotencyKey } from '../../utils/idempotency'
import { vehicleStatusLabels } from '../../utils/vehicleStatus'
import { VehicleStatusBadge } from '../../components/VehicleStatusBadge'
import { ApprovalStatusBadge } from '../../components/ApprovalStatusBadge'
import { CustomerSelect } from '../../components/CustomerSelect'
import { useAuth } from '../../hooks/useAuth'
import {
  canManageVehicles,
  canManageVehiclePhotos,
  canRunSalesFlow,
  canViewFinancials,
  canViewSalesPricing,
} from '../../utils/permissions'

const EXPENSE_CATEGORIES = ['維修支出', '美容支出', '代辦支出', '拍場支出', '其他支出'] as const

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

type ActiveModal = 'list' | 'reserve' | 'final-payment' | 'close-sale' | 'expense' | 'purchase-price' | null

export function VehicleDetail() {
  const { user } = useAuth()
  const canViewFinance = canViewFinancials(user?.role)
  const canViewSalesPrice = canViewSalesPricing(user?.role)
  const canManage = canManageVehicles(user?.role)
  const canManagePhotos = canManageVehiclePhotos(user?.role)
  const canSell = canRunSalesFlow(user?.role)
  const canReportExpense = canRunSalesFlow(user?.role)
  const { id } = useParams<{ id: string }>()
  const vehicleId = Number(id)
  const [detail, setDetail] = useState<VehicleDetailResponse | null>(null)
  const [cashAccounts, setCashAccounts] = useState<CashAccountOption[]>([])
  const [commissionAgents, setCommissionAgents] = useState<CommissionAgent[]>([])
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
    if (user?.role === 'admin' || user?.role === 'manager') {
      listCommissionAgentOptions()
        .then(setCommissionAgents)
        .catch(() => setCommissionAgents([]))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (error) {
    return <p className="text-sm text-error">{error}</p>
  }

  if (!detail) {
    return <p className="text-sm text-fg-muted">載入中...</p>
  }

  const { vehicle, summary, sales_collection_summary: salesCollectionSummary, money_entries: moneyEntries } = detail

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
    sales_agent_id: string
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
        sales_agent_id: form.sales_agent_id ? Number(form.sales_agent_id) : undefined,
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

  async function handleExpense(form: {
    category: string
    amount: string
    cash_account_id: string
    entry_date: string
    counterparty_name: string
    description: string
    idempotency_key: string
  }) {
    setSubmitting(true)
    setFormError(null)
    try {
      await recordVehicleExpense(vehicleId, {
        category: form.category as (typeof EXPENSE_CATEGORIES)[number],
        amount: Number(form.amount),
        cash_account_id: Number(form.cash_account_id),
        entry_date: form.entry_date || undefined,
        counterparty_name: form.counterparty_name || undefined,
        description: form.description || undefined,
        idempotency_key: form.idempotency_key,
      })
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '上報整備支出失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handlePurchasePrice(form: { purchase_price: string }) {
    setSubmitting(true)
    setFormError(null)
    try {
      await updateVehiclePurchasePrice(vehicle, Number(form.purchase_price))
      closeModal()
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '收購價更新失敗，請稍後再試'))
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
        {canReportExpense && !['sold', 'cancelled'].includes(vehicle.status) && (
          <button
            onClick={() => setActiveModal('expense')}
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            上報整備支出
          </button>
        )}
      </div>

      <VehiclePhotosPanel vehicleId={vehicleId} canManage={canManagePhotos} />

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
          {user?.role === 'admin' && !detail.commission_attribution_lock && (
            <button
              type="button"
              onClick={() => setActiveModal('purchase-price')}
              className="mt-3 min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm font-medium text-primary hover:bg-surface-2"
            >
              {vehicle.purchase_price === null || vehicle.purchase_price === undefined ? '補登收購價' : '修正收購價'}
            </button>
          )}
          <InfoRow label="收車人" value={vehicle.purchase_agent?.name ?? '-'} />
        </Panel>

        <Panel title="銷售資料">
          {canViewSalesPrice && <InfoRow label="開價" value={formatCurrency(vehicle.asking_price)} />}
          {canViewSalesPrice && <InfoRow label="底價" value={formatCurrency(vehicle.floor_price)} />}
          {canViewSalesPrice && <InfoRow label="成交價" value={formatCurrency(vehicle.sold_price)} />}
          <InfoRow label="買方姓名" value={vehicle.buyer_name ?? '-'} />
          <InfoRow label="買方電話" value={vehicle.buyer_phone ?? '-'} />
          <InfoRow label="成交日期" value={vehicle.sold_at ? vehicle.sold_at.slice(0, 10) : '-'} />
          <InfoRow label="賣車人" value={vehicle.sales_agent?.name ?? '-'} />
        </Panel>
      </div>

      {detail.commission_attribution_lock && (
        <div className="rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm text-fg">
          <p className="font-medium">獎金歸屬已鎖定</p>
          <p className="mt-1 text-fg-muted">{detail.commission_attribution_lock.reason}</p>
          <Link to={`/salary/periods/${detail.commission_attribution_lock.id}`} className="mt-2 inline-block font-medium text-primary hover:underline">
            查看 {detail.commission_attribution_lock.period_month} 薪資月份
          </Link>
        </div>
      )}

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

      {salesCollectionSummary && (
        <Panel title="銷售收款摘要">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">成交價</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">{formatCurrency(salesCollectionSummary.sold_price)}</p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">已核准收款</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">
                {formatCurrency(salesCollectionSummary.approved_collection_total)}
              </p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">待老闆核准收款</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">
                {formatCurrency(salesCollectionSummary.pending_collection_total)}
              </p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">已核准退款</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">
                {formatCurrency(salesCollectionSummary.approved_refund_total)}
              </p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">待核准退款</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">
                {formatCurrency(salesCollectionSummary.pending_refund_total)}
              </p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">已記錄淨收款</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">
                {formatCurrency(salesCollectionSummary.net_recorded_collection_total)}
              </p>
            </div>
            <div className="rounded-xl bg-surface-2 p-4">
              <p className="text-xs text-fg-muted">待收差額</p>
              <p className="mt-1 text-lg font-semibold text-fg tabular-nums">{formatCurrency(salesCollectionSummary.remaining_amount)}</p>
            </div>
          </div>
        </Panel>
      )}

      <Panel title={canViewFinance ? '單車收支明細' : '銷售 / 上報紀錄'}>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">日期</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">收支</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">分類</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">金額</th>
                {canViewFinance && <th className="px-3 py-2 text-left font-medium text-fg-muted">資金帳戶</th>}
                <th className="px-3 py-2 text-left font-medium text-fg-muted">審核狀態</th>
                <th className="px-3 py-2 text-left font-medium text-fg-muted">說明</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {moneyEntries.length === 0 && (
                <tr>
                  <td colSpan={canViewFinance ? 6 : 5} className="px-3 py-4 text-center text-fg-muted">
                    尚無收支紀錄
                  </td>
                </tr>
              )}
              {moneyEntries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-3 py-2">{entry.entry_date}</td>
                  <td className="px-3 py-2">{entry.direction === 'income' ? '收入' : '支出'}</td>
                  <td className="px-3 py-2">{entry.category}</td>
                  <td className="px-3 py-2 tabular-nums">{formatCurrency(entry.amount)}</td>
                  {canViewFinance && <td className="px-3 py-2">{entry.cash_account?.name ?? '-'}</td>}
                  <td className="px-3 py-2">
                    {entry.approval_status ? <ApprovalStatusBadge status={entry.approval_status} /> : '-'}
                  </td>
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
          commissionAgents={commissionAgents}
          isSales={user?.role === 'sales'}
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
      {activeModal === 'expense' && (
        <ExpenseModal
          onClose={closeModal}
          onSubmit={handleExpense}
          error={formError}
          submitting={submitting}
          cashAccounts={cashAccounts}
          isAdmin={user?.role === 'admin'}
        />
      )}
      {activeModal === 'purchase-price' && (
        <PurchasePriceModal
          currentPrice={vehicle.purchase_price}
          onClose={closeModal}
          onSubmit={handlePurchasePrice}
          error={formError}
          submitting={submitting}
        />
      )}
    </div>
  )
}

function PurchasePriceModal({
  currentPrice,
  onClose,
  onSubmit,
  error,
  submitting,
}: {
  currentPrice: number | null | undefined
  onClose: () => void
  onSubmit: (form: { purchase_price: string }) => void
  error: string | null
  submitting: boolean
}) {
  const [purchasePrice, setPurchasePrice] = useState(currentPrice?.toString() ?? '')

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ purchase_price: purchasePrice })
  }

  return (
    <Modal title={currentPrice === null || currentPrice === undefined ? '補登收購價' : '修正收購價'} onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <p className="text-sm leading-6 text-fg-muted">
          收購價會影響單車毛利與薪資獎金。薪資月份確認或發薪後，系統將禁止修改。
        </p>
        <Field label="收購價" value={purchasePrice} onChange={setPurchasePrice} type="number" required />
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting || purchasePrice === '' || Number(purchasePrice) < 0}
          className="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '儲存中...' : '儲存收購價'}
        </button>
      </form>
    </Modal>
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
  commissionAgents,
  isSales,
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
    sales_agent_id: string
  }) => void
  error: string | null
  submitting: boolean
  cashAccounts: CashAccountOption[]
  commissionAgents: CommissionAgent[]
  isSales: boolean
}) {
  const [buyer_name, setBuyerName] = useState('')
  const [buyer_phone, setBuyerPhone] = useState('')
  const [buyer_customer_id, setBuyerCustomerId] = useState('')
  const [buyerCustomerLabel, setBuyerCustomerLabel] = useState('')
  const [sold_price, setSoldPrice] = useState('')
  const [deposit_amount, setDepositAmount] = useState('')
  const [cash_account_id, setCashAccountId] = useState('')
  const [description, setDescription] = useState('')
  const [sales_agent_id, setSalesAgentId] = useState('')
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
      sales_agent_id,
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
        {!isSales && (
          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">
              賣車人<span className="text-error"> *</span>
            </label>
            <select
              required
              value={sales_agent_id}
              onChange={(event) => setSalesAgentId(event.target.value)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            >
              <option value="">請選擇實際賣車人</option>
              {commissionAgents.map((agent) => <option key={agent.id} value={agent.id}>{agent.name}</option>)}
            </select>
          </div>
        )}
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

function ExpenseModal({
  onClose,
  onSubmit,
  error,
  submitting,
  cashAccounts,
  isAdmin,
}: {
  onClose: () => void
  onSubmit: (form: {
    category: string
    amount: string
    cash_account_id: string
    entry_date: string
    counterparty_name: string
    description: string
    idempotency_key: string
  }) => void
  error: string | null
  submitting: boolean
  cashAccounts: CashAccountOption[]
  isAdmin: boolean
}) {
  const [category, setCategory] = useState<string>(EXPENSE_CATEGORIES[0])
  const [amount, setAmount] = useState('')
  const [cash_account_id, setCashAccountId] = useState('')
  const [entry_date, setEntryDate] = useState('')
  const [counterparty_name, setCounterpartyName] = useState('')
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
      category,
      amount,
      cash_account_id,
      entry_date,
      counterparty_name,
      description,
      idempotency_key: idempotencyKey,
    })
  }

  return (
    <Modal title="上報整備支出" onClose={onClose}>
      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div>
          <label className="mb-1 block text-sm font-medium text-fg-muted">
            分類<span className="text-error"> *</span>
          </label>
          <select
            required
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          >
            {EXPENSE_CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
        <Field label="金額" value={amount} onChange={setAmount} type="number" required />
        <CashAccountField cashAccounts={cashAccounts} value={cash_account_id} onChange={setCashAccountId} />
        <Field label="支出日期（預設今天）" value={entry_date} onChange={setEntryDate} type="date" />
        <Field label="對象" value={counterparty_name} onChange={setCounterpartyName} />
        <div>
          <label className="mb-1 block text-sm font-medium text-fg-muted">說明</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
        </div>
        <p className="text-xs text-fg-muted">
          {isAdmin ? '送出後直接計入正式支出。' : '送出後為待審核狀態，需老闆核准後才計入正式支出。'}
        </p>
        {error && <p className="text-sm text-error">{error}</p>}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
        >
          {submitting ? '送出中...' : '送出申請'}
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

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function VehiclePhotosPanel({ vehicleId, canManage }: { vehicleId: number; canManage: boolean }) {
  const [photos, setPhotos] = useState<VehiclePhoto[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [canRetryUpload, setCanRetryUpload] = useState(false)
  // 保留失敗嘗試的 idempotency_key 與 File 物件，讓「重試上傳」能用同一把 key 重送
  // 同一批檔案。若不保留、逼使用者透過檔案選擇器重新選檔，會產生新的 key：若上一次
  // 請求其實已經在伺服器端成功（例如只是客戶端逾時），新的 key 會被後端當成全新的
  // 一次上傳，重複建立照片（Codex adversarial review 指出）。
  const pendingUploadRef = useRef<{ key: string; files: File[] } | null>(null)
  const [busyPhotoId, setBusyPhotoId] = useState<number | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  // 每次「屬於目前車輛」的載入都會遞增，非最新一次的回應在 resolve 時直接捨棄，避免
  // 連續操作時，較舊、較慢的回應覆蓋掉已經是最新的照片清單（race condition）。
  const requestIdRef = useRef(0)
  // 記錄目前 render 對應的車輛 id。上傳 / 刪除 / 設封面 / 排序等操作完成後觸發的
  // loadPhotos() 是以「操作發起當下」的 vehicleId 呼叫；如果操作進行中使用者已經切換
  // 到別台車，這個刷新回來的就是舊車輛的照片，不該套用到目前畫面。
  const vehicleIdRef = useRef(vehicleId)

  function loadPhotos(forVehicleId: number) {
    // 呼叫當下就先擋掉「已經不是目前顯示中車輛」的刷新（例如上傳/刪除等操作完成觸發
    // 的刷新，但使用者早已切換到別台車），完全不遞增 requestIdRef、不打 API、不動
    // loading 狀態。這一步很關鍵：如果讓這種舊車輛的刷新照樣遞增 requestIdRef，會把
    // 「目前車輛」自己合法、仍在飛行中的請求所持有的 requestId 比下去，害那個真正該
    // 顯示的回應在 resolve 時被誤判成「非最新」而被捨棄，導致 loading 卡住、照片也不會
    // 出現，等於被一個完全無關的舊車輛操作打斷了目前車輛正常的載入。
    if (vehicleIdRef.current !== forVehicleId) return
    const requestId = ++requestIdRef.current
    setLoading(true)
    listVehiclePhotos(forVehicleId)
      .then((data) => {
        if (requestIdRef.current !== requestId || vehicleIdRef.current !== forVehicleId) return
        setPhotos(data)
        setLoadError(null)
      })
      .catch(() => {
        if (requestIdRef.current !== requestId || vehicleIdRef.current !== forVehicleId) return
        setLoadError('車輛照片載入失敗')
      })
      .finally(() => {
        if (requestIdRef.current !== requestId || vehicleIdRef.current !== forVehicleId) return
        setLoading(false)
      })
  }

  useEffect(() => {
    vehicleIdRef.current = vehicleId
    setPhotos([])
    setLoadError(null)
    setUploadError(null)
    setCanRetryUpload(false)
    pendingUploadRef.current = null
    loadPhotos(vehicleId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicleId])

  async function runUpload(idempotencyKey: string, files: File[]) {
    setUploading(true)
    setUploadError(null)
    try {
      await uploadVehiclePhotos(vehicleId, files, idempotencyKey)
      pendingUploadRef.current = null
      setCanRetryUpload(false)
      loadPhotos(vehicleId)
    } catch (err) {
      // 失敗時保留這次的 key 與檔案供「重試上傳」使用（見上方 pendingUploadRef 註解），
      // 不清除、不重新產生 key。
      pendingUploadRef.current = { key: idempotencyKey, files }
      setCanRetryUpload(true)
      setUploadError(extractErrorMessage(err, '上傳照片失敗，請稍後再試'))
    } finally {
      setUploading(false)
    }
  }

  async function handleFilesSelected(event: React.ChangeEvent<HTMLInputElement>) {
    const files = event.target.files ? Array.from(event.target.files) : []
    event.target.value = ''
    if (files.length === 0) return
    // 使用者透過檔案選擇器主動選了新的一批檔案，視為全新的上傳嘗試（產生新的
    // idempotency_key），先前失敗、尚未成功的批次即視為放棄，不再保留其重試狀態。
    await runUpload(generateIdempotencyKey(), files)
  }

  function handleRetryUpload() {
    if (!pendingUploadRef.current) return
    void runUpload(pendingUploadRef.current.key, pendingUploadRef.current.files)
  }

  async function handleDelete(photo: VehiclePhoto) {
    if (!window.confirm('確定要刪除這張照片嗎？此動作無法復原。')) return
    setBusyPhotoId(photo.id)
    setActionError(null)
    try {
      await deleteVehiclePhoto(vehicleId, photo.id)
      loadPhotos(vehicleId)
    } catch (err) {
      setActionError(extractErrorMessage(err, '刪除照片失敗，請稍後再試'))
    } finally {
      setBusyPhotoId(null)
    }
  }

  async function handleSetCover(photo: VehiclePhoto) {
    setBusyPhotoId(photo.id)
    setActionError(null)
    try {
      await setCoverVehiclePhoto(vehicleId, photo.id)
      loadPhotos(vehicleId)
    } catch (err) {
      setActionError(extractErrorMessage(err, '設定封面失敗，請稍後再試'))
    } finally {
      setBusyPhotoId(null)
    }
  }

  async function handleMove(photo: VehiclePhoto, direction: 'left' | 'right') {
    const index = photos.findIndex((p) => p.id === photo.id)
    const targetIndex = direction === 'left' ? index - 1 : index + 1
    if (index === -1 || targetIndex < 0 || targetIndex >= photos.length) return
    const reordered = [...photos]
    ;[reordered[index], reordered[targetIndex]] = [reordered[targetIndex], reordered[index]]
    setPhotos(reordered)
    setBusyPhotoId(photo.id)
    setActionError(null)
    try {
      await reorderVehiclePhotos(
        vehicleId,
        reordered.map((p) => p.id),
      )
      loadPhotos(vehicleId)
    } catch (err) {
      setActionError(extractErrorMessage(err, '調整排序失敗，請稍後再試'))
      loadPhotos(vehicleId)
    } finally {
      setBusyPhotoId(null)
    }
  }

  return (
    <Panel title="車輛照片">
      {canManage && (
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            className="hidden"
            onChange={handleFilesSelected}
          />
          <button
            type="button"
            disabled={uploading}
            onClick={() => fileInputRef.current?.click()}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
          >
            {uploading ? '上傳中...' : '上傳照片'}
          </button>
          {uploadError && (
            <div className="flex items-center gap-2">
              <p className="text-sm text-error">{uploadError}</p>
              {canRetryUpload && (
                <button
                  type="button"
                  disabled={uploading}
                  onClick={handleRetryUpload}
                  className="text-sm font-medium text-primary hover:underline disabled:opacity-50"
                >
                  重試上傳
                </button>
              )}
            </div>
          )}
        </div>
      )}

      {actionError && <p className="mb-3 text-sm text-error">{actionError}</p>}

      {loadError && <p className="text-sm text-error">{loadError}</p>}

      {!loadError && loading && <p className="text-sm text-fg-muted">載入中...</p>}

      {!loadError && !loading && photos.length === 0 && (
        <p className="text-sm text-fg-muted">尚無照片</p>
      )}

      {!loadError && !loading && photos.length > 0 && (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {photos.map((photo, index) => {
            const busy = busyPhotoId === photo.id
            return (
              <div key={photo.id} className="overflow-hidden rounded-xl border border-border bg-surface-2">
                <div className="relative aspect-[4/3] w-full">
                  <img
                    src={photo.thumbnail_url}
                    alt={photo.original_filename}
                    className="h-full w-full object-cover"
                  />
                  {photo.is_cover && (
                    <span className="absolute left-2 top-2 rounded-md bg-primary px-2 py-0.5 text-xs font-medium text-primary-fg">
                      封面
                    </span>
                  )}
                </div>
                <div className="flex flex-col gap-2 p-2">
                  <p className="truncate text-xs text-fg-muted" title={photo.original_filename}>
                    {photo.original_filename}
                  </p>
                  <p className="text-xs text-fg-subtle">{formatFileSize(photo.size)}</p>
                  {canManage && (
                    <div className="flex flex-wrap items-center gap-1">
                      <button
                        type="button"
                        disabled={busy || index === 0}
                        onClick={() => handleMove(photo, 'left')}
                        className="rounded-md border border-border-strong px-2 py-1 text-xs text-fg-muted hover:bg-surface disabled:opacity-40"
                      >
                        ←
                      </button>
                      <button
                        type="button"
                        disabled={busy || index === photos.length - 1}
                        onClick={() => handleMove(photo, 'right')}
                        className="rounded-md border border-border-strong px-2 py-1 text-xs text-fg-muted hover:bg-surface disabled:opacity-40"
                      >
                        →
                      </button>
                      {!photo.is_cover && (
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => handleSetCover(photo)}
                          className="rounded-md border border-border-strong px-2 py-1 text-xs text-fg-muted hover:bg-surface disabled:opacity-40"
                        >
                          設封面
                        </button>
                      )}
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => handleDelete(photo)}
                        className="rounded-md border border-error/40 px-2 py-1 text-xs text-error hover:bg-error/10 disabled:opacity-40"
                      >
                        刪除
                      </button>
                    </div>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </Panel>
  )
}
