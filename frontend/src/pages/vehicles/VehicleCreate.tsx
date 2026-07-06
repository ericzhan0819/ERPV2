import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { createVehicle } from '../../api/vehicles'
import { CustomerSelect } from '../../components/CustomerSelect'
import type { CreateVehiclePayload } from '../../types/vehicle'

interface FormState {
  brand: string
  model: string
  year: string
  license_plate: string
  vin: string
  mileage_km: string
  color: string
  purchase_date: string
  purchase_source_type: string
  seller_name: string
  seller_phone: string
  seller_customer_id: string
  purchase_price: string
  notes: string
}

const initialState: FormState = {
  brand: '',
  model: '',
  year: '',
  license_plate: '',
  vin: '',
  mileage_km: '',
  color: '',
  purchase_date: '',
  purchase_source_type: '',
  seller_name: '',
  seller_phone: '',
  seller_customer_id: '',
  purchase_price: '',
  notes: '',
}

function buildPayload(form: FormState): CreateVehiclePayload {
  const payload: CreateVehiclePayload = {
    brand: form.brand,
    model: form.model,
  }
  if (form.year) payload.year = Number(form.year)
  if (form.license_plate) payload.license_plate = form.license_plate
  if (form.vin) payload.vin = form.vin
  if (form.mileage_km) payload.mileage_km = Number(form.mileage_km)
  if (form.color) payload.color = form.color
  if (form.purchase_date) payload.purchase_date = form.purchase_date
  if (form.purchase_source_type) payload.purchase_source_type = form.purchase_source_type
  if (form.seller_name) payload.seller_name = form.seller_name
  if (form.seller_phone) payload.seller_phone = form.seller_phone
  if (form.seller_customer_id) payload.seller_customer_id = Number(form.seller_customer_id)
  if (form.purchase_price) payload.purchase_price = Number(form.purchase_price)
  if (form.notes) payload.notes = form.notes
  return payload
}

interface FieldProps {
  label: string
  value: string
  onChange: (value: string) => void
  type?: string
  required?: boolean
  readOnly?: boolean
}

function Field({ label, value, onChange, type = 'text', required, readOnly }: FieldProps) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-fg-muted">{label}</label>
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

export function VehicleCreate() {
  const navigate = useNavigate()
  const [form, setForm] = useState<FormState>(initialState)
  const [sellerCustomerLabel, setSellerCustomerLabel] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set<K extends keyof FormState>(key: K, value: string) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (!form.license_plate && !form.vin) {
      setError('車牌或 VIN 至少需填寫一項')
      return
    }

    setSubmitting(true)
    try {
      const vehicle = await createVehicle(buildPayload(form))
      navigate(`/vehicles/${vehicle.id}`)
    } catch (err) {
      if (isAxiosError(err) && err.response?.data?.message) {
        setError(err.response.data.message)
      } else {
        setError('新增車輛失敗，請稍後再試')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-fg">新增買入車輛</h1>
        <p className="mt-1 text-sm text-fg-muted">建立後系統會自動產生庫存編號，狀態為整備中</p>
      </div>

      <form onSubmit={handleSubmit} className="max-w-3xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="廠牌" value={form.brand} onChange={(v) => set('brand', v)} required />
          <Field label="車型" value={form.model} onChange={(v) => set('model', v)} required />
          <Field label="年式" value={form.year} onChange={(v) => set('year', v)} type="number" />
          <Field label="車牌" value={form.license_plate} onChange={(v) => set('license_plate', v)} />
          <Field label="VIN / 車身號碼" value={form.vin} onChange={(v) => set('vin', v)} />
          <Field label="里程" value={form.mileage_km} onChange={(v) => set('mileage_km', v)} type="number" />
          <Field label="顏色" value={form.color} onChange={(v) => set('color', v)} />
          <Field label="買入日期" value={form.purchase_date} onChange={(v) => set('purchase_date', v)} type="date" />
          <Field label="買入來源" value={form.purchase_source_type} onChange={(v) => set('purchase_source_type', v)} />
          <CustomerSelect
            label="關聯客戶（賣方）"
            value={form.seller_customer_id}
            selectedLabel={sellerCustomerLabel}
            onChange={(customerId, customer) => {
              set('seller_customer_id', customerId)
              if (customer) {
                set('seller_name', customer.name)
                set('seller_phone', customer.phone ?? '')
                setSellerCustomerLabel(customer.name)
              } else {
                setSellerCustomerLabel('')
              }
            }}
          />
          <Field
            label="原車主 / 供應商"
            value={form.seller_name}
            onChange={(v) => set('seller_name', v)}
            readOnly={!!form.seller_customer_id}
          />
          <Field
            label="聯絡電話"
            value={form.seller_phone}
            onChange={(v) => set('seller_phone', v)}
            readOnly={!!form.seller_customer_id}
          />
          <Field label="收購價" value={form.purchase_price} onChange={(v) => set('purchase_price', v)} type="number" />
        </div>

        <div className="mt-4">
          <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea
            value={form.notes}
            onChange={(e) => set('notes', e.target.value)}
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
            {submitting ? '建立中...' : '建立車輛'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/vehicles')}
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            取消
          </button>
        </div>
      </form>
    </div>
  )
}
