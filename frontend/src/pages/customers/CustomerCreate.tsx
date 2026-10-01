import { extractFieldErrors } from '../../utils/fieldErrors'
import { useId, useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { createCustomer } from '../../api/customers'
import { FormAlert } from '../../components/FormAlert'
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
  error,
  label,
  value,
  onChange,
  required,
}: {
  error?: string
  label: string
  value: string
  onChange: (value: string) => void
  required?: boolean
}) {
  const inputId = useId()
  return (
    <div>
      <label htmlFor={inputId} className="mb-1 block text-sm font-medium text-fg-muted">
        {label}
        {required && <span className="text-error"> *</span>}
      </label>
      <input
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${inputId}-error` : undefined}
        id={inputId}
        type="text"
        required={required}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
      />
      {error && <p id={`${inputId}-error`} className="mt-1 text-sm text-error">{error}</p>}
    </div>
  )
}

export function CustomerCreate() {
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const navigate = useNavigate()
  const [form, setForm] = useState<FormState>(initialState)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    setError(null)
    setSubmitting(true)
    try {
      const customer = await createCustomer(buildPayload(form))
      navigate(`/customers/${customer.id}`)
    } catch (err) {
      setFieldErrors(extractFieldErrors(err))
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
      <h1 className="text-xl font-semibold text-fg">新增客戶</h1>

      <form onSubmit={handleSubmit} className="max-w-3xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <FormAlert message={error} focusOnShow className="mb-4" />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="姓名" value={form.name} error={fieldErrors["name"]} onChange={(v) => set('name', v)} required />
          <Field label="電話" value={form.phone} error={fieldErrors["phone"]} onChange={(v) => set('phone', v)} />
          <Field label="Line ID" value={form.line_id} error={fieldErrors["line_id"]} onChange={(v) => set('line_id', v)} />
          <div>
            <label htmlFor="customercreate-field-1" className="mb-1 block text-sm font-medium text-fg-muted">
              類型<span className="text-error"> *</span>
            </label>
            <select aria-invalid={Boolean(fieldErrors["customer_type"])} aria-describedby={fieldErrors["customer_type"] ? 'customercreate-field-1-error' : undefined}
              id="customercreate-field-1"
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
              {fieldErrors["customer_type"] && <p id="customercreate-field-1-error" className="mt-1 text-sm text-error">{fieldErrors["customer_type"]}</p>}
          </div>
          <Field label="來源" value={form.source} error={fieldErrors["source"]} onChange={(v) => set('source', v)} />
          <Field label="地址" value={form.address} error={fieldErrors["address"]} onChange={(v) => set('address', v)} />
        </div>

        <div className="mt-4">
          <label htmlFor="customercreate-field-2" className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
          <textarea aria-invalid={Boolean(fieldErrors["notes"])} aria-describedby={fieldErrors["notes"] ? 'customercreate-field-2-error' : undefined}
            id="customercreate-field-2"
            value={form.notes}
            onChange={(e) => set('notes', e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
              {fieldErrors["notes"] && <p id="customercreate-field-2-error" className="mt-1 text-sm text-error">{fieldErrors["notes"]}</p>}
        </div>

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
