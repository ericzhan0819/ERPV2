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
      <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-fg">薪資結算</h1>
          <p className="mt-1 text-sm text-fg-muted">建立、確認與發放每月薪資</p>
        </div>
        <div className="grid gap-2 sm:flex sm:flex-wrap sm:items-end">
          <label className="text-sm text-fg-muted sm:min-w-44">
            結算月份 <span className="text-error">*</span>
            <input
              type="month"
              max={currentMonth}
              value={month}
              onChange={(event) => setMonth(event.target.value)}
              className="form-control-touch mt-1 block w-full rounded-lg border border-border-strong px-3 py-2"
            />
          </label>
          <button
            disabled={saving || !month}
            onClick={createDraft}
            className="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg disabled:opacity-50"
          >
            {saving ? '建立中...' : '建立月份草稿'}
          </button>
        </div>
      </div>

      <div className="grid gap-2 sm:flex">
        <Link to="/salary/profiles" className="flex min-h-11 items-center justify-center rounded-lg border border-border-strong px-3 py-2 text-sm text-fg-muted hover:bg-surface-2">
          員工薪資設定
        </Link>
        <Link to="/salary/commission-plans" className="flex min-h-11 items-center justify-center rounded-lg border border-border-strong px-3 py-2 text-sm text-fg-muted hover:bg-surface-2">
          獎金方案
        </Link>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}
      <div className="grid gap-3 sm:hidden" aria-live="polite">
        {loading && <StateCard message="載入中..." />}
        {!loading && periods.length === 0 && <StateCard message="尚未建立薪資月份" />}
        {periods.map((period) => <PeriodCard key={period.id} period={period} />)}
      </div>

      <div className="hidden overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm sm:block">
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
        <Link to={`/salary/periods/${period.id}`} className="inline-flex min-h-11 items-center font-medium text-primary hover:underline">
          查看詳情
        </Link>
      </td>
    </tr>
  )
}

function PeriodCard({ period }: { period: SalaryPeriodListItem }) {
  return (
    <article className="rounded-2xl border border-border bg-surface p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold text-fg">{period.period_month}</h2>
        <SalaryStatusBadge status={period.status} />
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
        <div className="col-span-2">
          <dt className="text-xs text-fg-muted">獎金方案</dt>
          <dd className="mt-1 break-words text-fg">{period.commission_plan.name}</dd>
        </div>
        <div>
          <dt className="text-xs text-fg-muted">預估／實發總額</dt>
          <dd className="mt-1 font-semibold tabular-nums">{formatCurrency(period.net_pay_total)}</dd>
        </div>
        <div>
          <dt className="text-xs text-fg-muted">員工人數</dt>
          <dd className="mt-1">{period.settlement_count}</dd>
        </div>
      </dl>
      <Link
        to={`/salary/periods/${period.id}`}
        className="mt-4 flex min-h-11 w-full items-center justify-center rounded-lg border border-border-strong font-medium text-primary hover:bg-surface-2"
      >
        查看詳情
      </Link>
    </article>
  )
}

function StateCard({ message }: { message: string }) {
  return <p className="rounded-2xl border border-border bg-surface px-4 py-8 text-center text-sm text-fg-muted">{message}</p>
}

function EmptyRow({ message }: { message: string }) {
  return <tr><td colSpan={6} className="px-4 py-8 text-center text-fg-muted">{message}</td></tr>
}
