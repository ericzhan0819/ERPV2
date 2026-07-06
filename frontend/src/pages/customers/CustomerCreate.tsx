import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { createCustomer } from '../../api/customers'
import type { CustomerPayload, CustomerType } from '../../types/customer'

interface FormState {
  name: string
  phone: string
  line_id: string
  customer_type: CustomerType
  source: string
  address: string
  notes: string
}

const initialState: FormState = {
  name: '',
  phone: '',
  line_id: '',
  customer_type: 'other',
  source: '',
  address: '',
  notes: '',
}

function buildPayload(form: FormState): CustomerPayload {
  return {
    name: form.name,
    phone: form.phone || undefined,
    line_id: form.line_id || undefined,
    customer_type: form.customer_type,
    source: form.source || undefined,
    address: form.address || undefined,
    notes: form.notes || undefined,
  }
}

function Field({
  label,
  value,
  onChange,
  required,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  required?: boolean
}) {
  return (
    <div>
      <label className="mb-1 block text-sm font-medium text-fg-muted">
        {label}
        {required && <span className="text-error"> *</span>}
      </label>
      <input
        type="text"
        required={required}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
      />
    </div>
  )
}

export function CustomerCreate() {
  const navigate = useNavigate()
  const [form, setForm] = useState<FormState>(initialState)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      const customer = await createCustomer(buildPayload(form))
      navigate(`/customers/${customer.id}`)
    } catch (err) {
      if (isAxiosError(err) && err.response?.data?.message) {
        setError(err.response.data.message)
      } else {
        setError('新增客戶失敗，請稍後再試')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-fg">新增客戶</h1>
        <p className="mt-1 text-sm text-fg-muted">建立買方、賣方、同行或介紹客的基本資料</p>
      </div>

      <form onSubmit={handleSubmit} className="max-w-3xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="姓名" value={form.name} onChange={(v) => set('name', v)} required />
          <Field label="電話" value={form.phone} onChange={(v) => set('phone', v)} />
          <Field label="Line ID" value={form.line_id} onChange={(v) => set('line_id', v)} />
          <div>
            <label className="mb-1 block text-sm font-medium text-fg-muted">
              類型<span className="text-error"> *</span>
            </label>
            <select
              required
              value={form.customer_type}
              onChange={(e) => set('customer_type', e.target.value as CustomerType)}
              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
            >
              <option value="buyer">買方</option>
              <option value="seller">賣方</option>
              <option value="both">買賣方</option>
              <option value="other">其他</option>
            </select>
          </div>
          <Field label="來源" value={form.source} onChange={(v) => set('source', v)} />
          <Field label="地址" value={form.address} onChange={(v) => set('address', v)} />
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
            {submitting ? '建立中...' : '建立客戶'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/customers')}
            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
          >
            取消
          </button>
        </div>
      </form>
    </div>
  )
}
