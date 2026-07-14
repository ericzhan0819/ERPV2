import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { deleteCustomer, getCustomer, updateCustomer } from '../../api/customers'
import type { CustomerDetailResponse, CustomerRelatedVehicle, CustomerType } from '../../types/customer'
import { useAuth } from '../../hooks/useAuth'
import { canDeleteCustomer, canViewSalesPricing } from '../../utils/permissions'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null | undefined): string {
  return amount === null || amount === undefined ? '-' : currencyFormatter.format(amount)
}

const typeLabels: Record<CustomerType, string> = {
  buyer: '買方',
  seller: '賣方',
  both: '買賣方',
  other: '其他',
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <h2 className="mb-2 text-sm font-semibold text-fg-muted">{title}</h2>
      {children}
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

function VehicleTable({ vehicles, canViewSalesPrice }: { vehicles: CustomerRelatedVehicle[]; canViewSalesPrice: boolean }) {
  if (vehicles.length === 0) {
    return <p className="text-sm text-fg-muted">尚無相關車輛</p>
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-border text-sm">
        <thead>
          <tr>
            <th className="px-3 py-2 text-left font-medium text-fg-muted">庫存編號</th>
            <th className="px-3 py-2 text-left font-medium text-fg-muted">車輛</th>
            <th className="px-3 py-2 text-left font-medium text-fg-muted">狀態</th>
            {canViewSalesPrice && <th className="px-3 py-2 text-left font-medium text-fg-muted">成交價</th>}
            <th className="px-3 py-2 text-left font-medium text-fg-muted">成交日期</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {vehicles.map((vehicle) => (
            <tr key={vehicle.id}>
              <td className="px-3 py-2">
                <Link to={`/vehicles/${vehicle.id}`} className="font-medium text-fg hover:underline">
                  {vehicle.stock_no}
                </Link>
              </td>
              <td className="px-3 py-2">
                {vehicle.brand} {vehicle.model}
              </td>
              <td className="px-3 py-2">{vehicle.status}</td>
              {canViewSalesPrice && <td className="px-3 py-2 tabular-nums">{formatCurrency(vehicle.sold_price)}</td>}
              <td className="px-3 py-2">{vehicle.sold_at ? vehicle.sold_at.slice(0, 10) : '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function CustomerDetail() {
  const { user } = useAuth()
  const canViewSalesPrice = canViewSalesPricing(user?.role)
  const canDelete = canDeleteCustomer(user?.role)
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const customerId = Number(id)
  const [detail, setDetail] = useState<CustomerDetailResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({
    name: '',
    phone: '',
    line_id: '',
    customer_type: 'other' as CustomerType,
    source: '',
    address: '',
    notes: '',
  })
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function loadDetail() {
    getCustomer(customerId)
      .then((response) => {
        setDetail(response)
        setForm({
          name: response.customer.name,
          phone: response.customer.phone ?? '',
          line_id: response.customer.line_id ?? '',
          customer_type: response.customer.customer_type,
          source: response.customer.source ?? '',
          address: response.customer.address ?? '',
          notes: response.customer.notes ?? '',
        })
      })
      .catch(() => setError('客戶資料載入失敗'))
  }

  useEffect(() => {
    if (!id) return
    loadDetail()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (error) {
    return <p className="text-sm text-error">{error}</p>
  }

  if (!detail) {
    return <p className="text-sm text-fg-muted">載入中...</p>
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    setSubmitting(true)
    try {
      await updateCustomer(customerId, {
        // 清空欄位時要明確送出 null；若省略欄位，後端會保留原本的可空欄位值。
        name: form.name,
        phone: form.phone || null,
        line_id: form.line_id || null,
        customer_type: form.customer_type,
        source: form.source || null,
        address: form.address || null,
        notes: form.notes || null,
      })
      setEditing(false)
      loadDetail()
    } catch (err) {
      setFormError(extractErrorMessage(err, '更新客戶失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete() {
    if (!window.confirm('確定要刪除此客戶嗎？')) return
    try {
      await deleteCustomer(customerId)
      navigate('/customers')
    } catch (err) {
      setError(extractErrorMessage(err, '刪除客戶失敗'))
    }
  }

  const { customer, vehicles_as_seller: vehiclesAsSeller, vehicles_as_buyer: vehiclesAsBuyer } = detail

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">{customer.name}</h1>
          <p className="mt-1 text-sm text-fg-muted">{typeLabels[customer.customer_type]}</p>
        </div>
        <div className="flex items-center gap-3">
          {!editing && (
            <button
              onClick={() => setEditing(true)}
              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
            >
              編輯
            </button>
          )}
          {canDelete && (
            <button
              onClick={handleDelete}
              className="rounded-lg border border-error/40 px-4 py-2 text-sm font-medium text-error hover:bg-error/10"
            >
              刪除
            </button>
          )}
          <Link
            to="/customers"
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            返回列表
          </Link>
        </div>
      </div>

      {editing ? (
        <Panel title="編輯客戶資料">
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">
                  姓名<span className="text-error"> *</span>
                </label>
                <input
                  required
                  value={form.name}
                  onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">電話</label>
                <input
                  value={form.phone}
                  onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">Line ID</label>
                <input
                  value={form.line_id}
                  onChange={(e) => setForm((prev) => ({ ...prev, line_id: e.target.value }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">
                  類型<span className="text-error"> *</span>
                </label>
                <select
                  required
                  value={form.customer_type}
                  onChange={(e) => setForm((prev) => ({ ...prev, customer_type: e.target.value as CustomerType }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                >
                  <option value="buyer">買方</option>
                  <option value="seller">賣方</option>
                  <option value="both">買賣方</option>
                  <option value="other">其他</option>
                </select>
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">來源</label>
                <input
                  value={form.source}
                  onChange={(e) => setForm((prev) => ({ ...prev, source: e.target.value }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-fg-muted">地址</label>
                <input
                  value={form.address}
                  onChange={(e) => setForm((prev) => ({ ...prev, address: e.target.value }))}
                  className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
              </div>
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
              <textarea
                value={form.notes}
                onChange={(e) => setForm((prev) => ({ ...prev, notes: e.target.value }))}
                rows={3}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            {formError && <p className="text-sm text-error">{formError}</p>}
            <div className="flex gap-3">
              <button
                type="submit"
                disabled={submitting}
                className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
              >
                {submitting ? '儲存中...' : '儲存'}
              </button>
              <button
                type="button"
                onClick={() => setEditing(false)}
                className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
              >
                取消
              </button>
            </div>
          </form>
        </Panel>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Panel title="基本資料">
            <div className="flex flex-col gap-2 text-sm">
              <div className="flex justify-between border-b border-border py-2 last:border-0">
                <span className="text-fg-muted">電話</span>
                <span className="font-medium text-fg">{customer.phone ?? '-'}</span>
              </div>
              <div className="flex justify-between border-b border-border py-2 last:border-0">
                <span className="text-fg-muted">Line ID</span>
                <span className="font-medium text-fg">{customer.line_id ?? '-'}</span>
              </div>
              <div className="flex justify-between border-b border-border py-2 last:border-0">
                <span className="text-fg-muted">來源</span>
                <span className="font-medium text-fg">{customer.source ?? '-'}</span>
              </div>
              <div className="flex justify-between border-b border-border py-2 last:border-0">
                <span className="text-fg-muted">地址</span>
                <span className="font-medium text-fg">{customer.address ?? '-'}</span>
              </div>
            </div>
          </Panel>
          <Panel title="備註">
            <p className="whitespace-pre-wrap text-sm text-fg">{customer.notes || '無備註'}</p>
          </Panel>
        </div>
      )}

      <Panel title="作為賣方的車輛">
        <VehicleTable vehicles={vehiclesAsSeller} canViewSalesPrice={canViewSalesPrice} />
      </Panel>

      <Panel title="作為買方的車輛">
        <VehicleTable vehicles={vehiclesAsBuyer} canViewSalesPrice={canViewSalesPrice} />
      </Panel>
    </div>
  )
}
