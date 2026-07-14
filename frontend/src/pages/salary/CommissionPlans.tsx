import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { createCommissionPlan, listCommissionPlans } from '../../api/commissionPlans'
import type { CommissionPlan, CommissionPlanPayload } from '../../types/salary'
import { apiError, formatPercent } from './salaryUtils'

const initialForm: CommissionPlanPayload = {
  name: '',
  effective_from: new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' }),
  company_reserve_bps: 4000,
  purchase_bonus_bps: 2000,
  is_active: true,
  tiers: [
    { min_sales_count: 1, sales_bonus_bps: 2000 },
    { min_sales_count: 3, sales_bonus_bps: 3000 },
    { min_sales_count: 5, sales_bonus_bps: 5000 },
  ],
}

export function CommissionPlans() {
  const [plans, setPlans] = useState<CommissionPlan[]>([])
  const [form, setForm] = useState(initialForm)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function load() {
    listCommissionPlans()
      .then(setPlans)
      .catch((caught) => setError(apiError(caught, '獎金方案載入失敗')))
  }

  useEffect(() => { load() }, [])

  async function save() {
    setSaving(true)
    setError(null)
    try {
      await createCommissionPlan(form)
      setShowForm(false)
      setForm({ ...initialForm, name: '' })
      load()
    } catch (caught) {
      setError(apiError(caught, '建立獎金方案失敗'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">獎金方案</h1>
          <p className="mt-1 text-sm text-fg-muted">已使用方案永久唯讀；規則變更請建立新版本。</p>
        </div>
        <Link to="/salary" className="text-sm text-primary">返回薪資月份</Link>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}
      <button
        onClick={() => setShowForm((visible) => !visible)}
        className="w-fit rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg"
      >
        建立新方案
      </button>

      {showForm && (
        <CommissionPlanForm form={form} saving={saving} onChange={setForm} onSave={save} />
      )}

      <div className="grid gap-4">
        {plans.map((plan) => <CommissionPlanCard key={plan.id} plan={plan} />)}
      </div>
    </div>
  )
}

function CommissionPlanForm({
  form,
  saving,
  onChange,
  onSave,
}: {
  form: CommissionPlanPayload
  saving: boolean
  onChange: (form: CommissionPlanPayload) => void
  onSave: () => void
}) {
  function updateTier(index: number, field: 'min_sales_count' | 'sales_bonus_bps', value: number) {
    onChange({
      ...form,
      tiers: form.tiers.map((tier, tierIndex) => (
        tierIndex === index ? { ...tier, [field]: value } : tier
      )),
    })
  }

  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="方案名稱"
          value={form.name}
          onChange={(value) => onChange({ ...form, name: value })}
        />
        <TextField
          label="生效日"
          type="date"
          value={form.effective_from}
          onChange={(value) => onChange({ ...form, effective_from: value })}
        />
        <NumberField
          label="公司保留（基點）"
          value={form.company_reserve_bps}
          onChange={(value) => onChange({ ...form, company_reserve_bps: value })}
        />
        <NumberField
          label="收車獎金（基點）"
          value={form.purchase_bonus_bps}
          onChange={(value) => onChange({ ...form, purchase_bonus_bps: value })}
        />
      </div>

      <h3 className="mt-5 text-sm font-medium">賣車跨級獎金</h3>
      <div className="mt-2 grid gap-2">
        {form.tiers.map((tier, index) => (
          <div key={index} className="flex gap-2">
            <input
              aria-label={`第 ${index + 1} 級起始台數`}
              type="number"
              min="1"
              value={tier.min_sales_count}
              onChange={(event) => updateTier(index, 'min_sales_count', Number(event.target.value))}
              className="w-32 rounded-lg border border-border-strong bg-surface px-3 py-2"
            />
            <input
              aria-label={`第 ${index + 1} 級獎金基點`}
              type="number"
              value={tier.sales_bonus_bps}
              onChange={(event) => updateTier(index, 'sales_bonus_bps', Number(event.target.value))}
              className="w-40 rounded-lg border border-border-strong bg-surface px-3 py-2"
            />
          </div>
        ))}
      </div>
      <button
        disabled={saving || !form.name}
        onClick={onSave}
        className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg disabled:opacity-50"
      >
        {saving ? '建立中...' : '建立方案'}
      </button>
    </section>
  )
}

function CommissionPlanCard({ plan }: { plan: CommissionPlan }) {
  return (
    <section className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <div className="flex justify-between">
        <div>
          <h2 className="font-semibold text-fg">{plan.name}</h2>
          <p className="text-xs text-fg-muted">{plan.effective_from} 起生效</p>
        </div>
        <span className={`badge ${plan.is_used ? 'badge-slate' : 'badge-blue'}`}>
          {plan.is_used ? '已使用・唯讀' : '尚未使用'}
        </span>
      </div>
      <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <PlanMetric label="公司保留" value={formatPercent(plan.company_reserve_bps)} />
        <PlanMetric label="分配池" value={formatPercent(10000 - plan.company_reserve_bps)} />
        <PlanMetric label="收車獎金" value={formatPercent(plan.purchase_bonus_bps)} />
        <PlanMetric label="方案狀態" value={plan.is_active ? '啟用' : '停用'} />
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        {plan.tiers.map((tier) => (
          <span key={tier.id ?? tier.min_sales_count} className="rounded-lg bg-surface-2 px-3 py-2 text-sm">
            {tier.min_sales_count} 台起：{formatPercent(tier.sales_bonus_bps)}
          </span>
        ))}
      </div>
    </section>
  )
}

function TextField({ label, value, type = 'text', onChange }: {
  label: string
  value: string
  type?: string
  onChange: (value: string) => void
}) {
  return (
    <label className="text-sm">
      {label} <span className="text-error">*</span>
      <input
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="mt-1 w-full rounded-lg border border-border-strong bg-surface px-3 py-2"
      />
    </label>
  )
}

function NumberField({ label, value, onChange }: {
  label: string
  value: number
  onChange: (value: number) => void
}) {
  return (
    <label className="text-sm">
      {label} <span className="text-error">*</span>
      <input
        type="number"
        value={value}
        onChange={(event) => onChange(Number(event.target.value))}
        className="mt-1 w-full rounded-lg border border-border-strong bg-surface px-3 py-2"
      />
    </label>
  )
}

function PlanMetric({ label, value }: { label: string; value: string }) {
  return <div>{label}<br /><b>{value}</b></div>
}
