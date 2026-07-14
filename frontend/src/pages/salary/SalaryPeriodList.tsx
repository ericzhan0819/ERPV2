import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { createSalaryPeriod, listSalaryPeriods } from '../../api/salaryPeriods'
import type { SalaryPeriodListItem } from '../../types/salary'
import { SalaryStatusBadge } from './shared'
import { apiError, formatCurrency } from './salaryUtils'

const currentMonth = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' }).slice(0, 7)

export function SalaryPeriodList() {
  const [periods, setPeriods] = useState<SalaryPeriodListItem[]>([])
  const [month, setMonth] = useState(currentMonth)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function load() {
    setLoading(true)
    setError(null)
    listSalaryPeriods()
      .then(setPeriods)
      .catch((caught) => setError(apiError(caught, '薪資月份載入失敗')))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function createDraft() {
    setSaving(true)
    setError(null)
    try {
      await createSalaryPeriod(month)
      load()
    } catch (caught) {
      setError(apiError(caught, '建立薪資草稿失敗'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-fg">薪資結算</h1>
          <p className="mt-1 text-sm text-fg-muted">建立、確認與發放每月薪資</p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-sm text-fg-muted">
            結算月份 <span className="text-error">*</span>
            <input
              type="month"
              max={currentMonth}
              value={month}
              onChange={(event) => setMonth(event.target.value)}
              className="mt-1 block rounded-lg border border-border-strong bg-surface px-3 py-2 text-fg"
            />
          </label>
          <button
            disabled={saving || !month}
            onClick={createDraft}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg disabled:opacity-50"
          >
            {saving ? '建立中...' : '建立月份草稿'}
          </button>
        </div>
      </div>

      <div className="flex gap-2">
        <Link to="/salary/profiles" className="rounded-lg border border-border-strong px-3 py-2 text-sm text-fg-muted">
          員工薪資設定
        </Link>
        <Link to="/salary/commission-plans" className="rounded-lg border border-border-strong px-3 py-2 text-sm text-fg-muted">
          獎金方案
        </Link>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}
      <div className="overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              {['月份', '狀態', '獎金方案', '預估／實發總額', '員工人數', ''].map((heading) => (
                <th key={heading} className="px-4 py-3 text-left font-medium text-fg-muted">{heading}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && <EmptyRow message="載入中..." />}
            {!loading && periods.length === 0 && <EmptyRow message="尚未建立薪資月份" />}
            {periods.map((period) => <PeriodRow key={period.id} period={period} />)}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function PeriodRow({ period }: { period: SalaryPeriodListItem }) {
  return (
    <tr>
      <td className="px-4 py-3 font-medium text-fg">{period.period_month}</td>
      <td className="px-4 py-3"><SalaryStatusBadge status={period.status} /></td>
      <td className="px-4 py-3 text-fg-muted">{period.commission_plan.name}</td>
      <td className="px-4 py-3 tabular-nums">{formatCurrency(period.net_pay_total)}</td>
      <td className="px-4 py-3">{period.settlement_count}</td>
      <td className="px-4 py-3 text-right">
        <Link to={`/salary/periods/${period.id}`} className="font-medium text-primary hover:underline">
          查看詳情
        </Link>
      </td>
    </tr>
  )
}

function EmptyRow({ message }: { message: string }) {
  return <tr><td colSpan={6} className="px-4 py-8 text-center text-fg-muted">{message}</td></tr>
}
