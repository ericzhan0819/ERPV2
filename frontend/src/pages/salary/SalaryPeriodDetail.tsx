import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { listCashAccounts } from '../../api/cashAccounts'
import { listSalaryProfiles } from '../../api/salaryProfiles'
import {
  addSalaryAdjustment,
  confirmSalaryPeriod,
  deleteSalaryAdjustment,
  getSalaryPeriod,
  paySalaryPeriod,
  recalculateSalaryPeriod,
} from '../../api/salaryPeriods'
import type { CashAccountOption } from '../../types/cashAccount'
import type {
  CommissionPlanTier,
  SalaryCommissionWarning,
  SalaryAnomaly,
  SalaryPeriod,
  SalarySettlement,
} from '../../types/salary'
import { generateIdempotencyKey } from '../../utils/idempotency'
import { SalaryStatusBadge } from './shared'
import { apiError, formatCurrency, formatPercent } from './salaryUtils'

type AdjustmentForm = {
  user_id: number
  type: 'manual_addition' | 'manual_deduction'
  amount: string
  description: string
}

type PaymentForm = {
  cash_account_id: string
  payment_date: string
  idempotency_key: string
}

type CommissionState = 'enabled' | 'disabled' | 'loading' | 'unavailable'

const itemLabels: Record<string, string> = {
  base_salary: '底薪',
  fixed_allowance: '固定津貼',
  purchase_bonus: '收車獎金',
  sales_bonus: '賣車獎金',
  labor_insurance_deduction: '勞保扣款',
  health_insurance_deduction: '健保扣款',
  manual_addition: '其他加給',
  manual_deduction: '其他扣款',
}

export function SalaryPeriodDetail() {
  const id = Number(useParams().id)
  const [period, setPeriod] = useState<SalaryPeriod | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const actionInFlight = useRef(false)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [adjustment, setAdjustment] = useState<AdjustmentForm | null>(null)
  const [accounts, setAccounts] = useState<CashAccountOption[]>([])
  const [commissionEnabledByUser, setCommissionEnabledByUser] = useState<Record<number, boolean> | null>(null)
  const [commissionProfilesFailed, setCommissionProfilesFailed] = useState(false)
  const [payment, setPayment] = useState<PaymentForm>({
    cash_account_id: '',
    payment_date: new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' }),
    idempotency_key: generateIdempotencyKey(),
  })

  function load() {
    setError(null)
    getSalaryPeriod(id)
      .then(setPeriod)
      .catch((caught) => setError(apiError(caught, '薪資月份載入失敗')))
  }

  useEffect(load, [id])
  useEffect(() => {
    listCashAccounts()
      .then((loaded) => setAccounts(loaded.filter((account) => account.is_active)))
      .catch(() => setError('資金帳戶載入失敗'))
    setCommissionProfilesFailed(false)
    listSalaryProfiles()
      .then((profiles) => setCommissionEnabledByUser(Object.fromEntries(
        profiles.map((profile) => [profile.user_id, profile.commission_enabled]),
      )))
      .catch(() => setCommissionProfilesFailed(true))
  }, [])

  async function runAction(action: () => Promise<unknown>) {
    if (actionInFlight.current) return
    actionInFlight.current = true
    setBusy(true)
    setError(null)
    try {
      await action()
      load()
    } catch (caught) {
      setError(apiError(caught, '薪資操作失敗'))
    } finally {
      actionInFlight.current = false
      setBusy(false)
    }
  }

  async function addAdjustment() {
    if (!adjustment) return
    await runAction(async () => {
      await addSalaryAdjustment(id, { ...adjustment, amount: Number(adjustment.amount) })
      setAdjustment(null)
    })
  }

  if (!period) {
    return <p className={error ? 'text-error' : 'text-fg-muted'}>{error ?? '載入中...'}</p>
  }

  const tiers = [...period.commission_plan.tiers].sort((a, b) => a.min_sales_count - b.min_sales_count)
  const commissionDisabledAgentIds = new Set(
    (period.commission_warnings ?? [])
      .filter((warning) => warning.code === 'commission_disabled')
      .map((warning) => warning.agent_id),
  )

  return (
    <div className="flex flex-col gap-6">
      <PeriodHeader period={period} />
      {error && <p className="rounded-lg bg-error/10 p-3 text-sm text-error">{error}</p>}
      <CompanySummary period={period} />

      {period.status === 'draft' && (
        <DraftActions
          busy={busy}
          blocked={Boolean(period.has_blocking_issues)}
          onRecalculate={() => runAction(() => recalculateSalaryPeriod(id))}
          onConfirm={() => runAction(() => confirmSalaryPeriod(id))}
        />
      )}

      {period.status === 'confirmed' && (
        <PaymentPanel
          period={period}
          accounts={accounts}
          form={payment}
          busy={busy}
          onChange={setPayment}
          onPay={() => runAction(() => paySalaryPeriod(id, {
            cash_account_id: Number(payment.cash_account_id),
            payment_date: payment.payment_date,
            idempotency_key: payment.idempotency_key,
          }))}
        />
      )}

      {period.status === 'paid' && (
        <p className="rounded-lg bg-success/10 p-3 text-sm text-success">
          已於 {period.payment_date} 由 {period.cash_account?.name ?? '指定帳戶'} 發薪，本月份唯讀。
        </p>
      )}

      {period.status === 'draft' && period.anomalies && period.anomalies.length > 0 && (
        <AnomalyPanel anomalies={period.anomalies} />
      )}

      {period.status === 'draft' && period.commission_warnings && period.commission_warnings.length > 0 && (
        <CommissionWarningPanel warnings={period.commission_warnings} />
      )}

      <div className="grid gap-4">
        {period.settlements.map((settlement) => (
          <SettlementCard
            key={settlement.id}
            settlement={settlement}
            tiers={tiers}
            draft={period.status === 'draft'}
            commissionState={commissionStateFor(
              settlement.user_id,
              commissionEnabledByUser,
              commissionDisabledAgentIds,
              commissionProfilesFailed,
            )}
            expanded={expanded === settlement.id}
            onToggle={() => setExpanded(expanded === settlement.id ? null : settlement.id)}
            onAddAdjustment={() => setAdjustment({
              user_id: settlement.user_id,
              type: 'manual_addition',
              amount: '',
              description: '',
            })}
            onDeleteItem={(itemId) => runAction(() => deleteSalaryAdjustment(id, itemId))}
          />
        ))}
      </div>

      {adjustment && (
        <AdjustmentModal
          form={adjustment}
          busy={busy}
          onChange={setAdjustment}
          onSave={addAdjustment}
          onCancel={() => setAdjustment(null)}
        />
      )}
    </div>
  )
}

function PeriodHeader({ period }: { period: SalaryPeriod }) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold text-fg">{period.period_month} 薪資</h1>
          <SalaryStatusBadge status={period.status} />
        </div>
        <p className="mt-1 text-sm text-fg-muted">適用方案：{period.commission_plan.name}</p>
      </div>
      <Link to="/salary" className="flex min-h-11 items-center text-sm font-medium text-primary">返回薪資月份</Link>
    </div>
  )
}

function CompanySummary({ period }: { period: SalaryPeriod }) {
  const reserve = period.totals.company_reserve_total
  const remaining = period.totals.company_remaining_total
  return (
    <section className="grid gap-4 sm:grid-cols-3">
      <Summary label="全公司實發合計" value={formatCurrency(period.totals.net_pay)} />
      <Summary label="公司營運保留" value={reserve === null ? '請先重算草稿' : formatCurrency(reserve)} />
      <Summary label="公司剩餘分配額" value={remaining === null ? '請先重算草稿' : formatCurrency(remaining)} />
    </section>
  )
}

function DraftActions({ busy, blocked, onRecalculate, onConfirm }: {
  busy: boolean
  blocked: boolean
  onRecalculate: () => void
  onConfirm: () => void
}) {
  function confirm() {
    if (window.confirm('確認後薪資快照與車輛歸屬將鎖定，確定要確認結算？')) onConfirm()
  }
  return (
    <div className="grid gap-2 sm:flex sm:flex-wrap">
      <button disabled={busy} onClick={onRecalculate} className="min-h-11 rounded-lg border border-border-strong px-4 py-2 text-sm disabled:opacity-50">
        {busy ? '處理中...' : '重算草稿'}
      </button>
      <button
        disabled={busy || blocked}
        onClick={confirm}
        className="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg disabled:opacity-50"
      >
        {busy ? '處理中...' : '確認結算'}
      </button>
    </div>
  )
}

function PaymentPanel({ period, accounts, form, busy, onChange, onPay }: {
  period: SalaryPeriod
  accounts: CashAccountOption[]
  form: PaymentForm
  busy: boolean
  onChange: (form: PaymentForm) => void
  onPay: () => void
}) {
  function update(field: 'cash_account_id' | 'payment_date', value: string) {
    onChange({ ...form, [field]: value, idempotency_key: generateIdempotencyKey() })
  }
  function pay() {
    if (window.confirm(`確定由所選帳戶發放 ${formatCurrency(period.totals.net_pay)}？`)) onPay()
  }
  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-semibold">發薪</h2>
      <div className="mt-3 grid gap-3 sm:flex sm:flex-wrap sm:items-end">
        <label className="min-w-0 text-sm sm:min-w-52">
          資金帳戶 <span className="text-error">*</span>
          <select
            value={form.cash_account_id}
            onChange={(event) => update('cash_account_id', event.target.value)}
            className="form-control-touch mt-1 block w-full rounded-lg border border-border-strong px-3 py-2"
          >
            <option value="">請選擇</option>
            {accounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}
          </select>
        </label>
        <label className="min-w-0 text-sm sm:min-w-44">
          發薪日期 <span className="text-error">*</span>
          <input
            type="date"
            value={form.payment_date}
            onChange={(event) => update('payment_date', event.target.value)}
            className="form-control-touch mt-1 block w-full rounded-lg border border-border-strong px-3 py-2"
          />
        </label>
        <button
          disabled={busy || !form.cash_account_id}
          onClick={pay}
          className="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg disabled:opacity-50"
        >
          確認發薪
        </button>
      </div>
    </section>
  )
}

function AnomalyPanel({ anomalies }: { anomalies: SalaryAnomaly[] }) {
  return (
    <section className="rounded-2xl border border-error/30 bg-surface p-5">
      <h2 className="font-semibold text-error">阻擋異常（{anomalies.length}）</h2>
      <div className="mt-3 grid gap-2">
        {anomalies.map((anomaly, index) => (
          <div
            key={`${anomaly.vehicle_id}-${anomaly.code}-${index}`}
            className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-error/5 p-3 text-sm"
          >
            <span><b>{anomaly.stock_no}</b>：{anomaly.message}</span>
            {correctionPath(anomaly) ? (
              <Link
                to={correctionPath(anomaly) ?? '#'}
                className="flex min-h-11 items-center font-medium text-primary"
              >
                {anomaly.correction.label}
              </Link>
            ) : (
              <span className="text-fg-muted">請由系統管理員在後端完成來源確認後再重算草稿。</span>
            )}
          </div>
        ))}
      </div>
    </section>
  )
}

function CommissionWarningPanel({ warnings }: { warnings: SalaryCommissionWarning[] }) {
  return (
    <section className="rounded-2xl border border-warning/40 bg-surface p-5">
      <h2 className="font-semibold text-warning">獎金設定提示（不阻擋確認）</h2>
      <p className="mt-1 text-sm text-fg-muted">
        下列歸屬人不會取得對應獎金，金額將保留在公司剩餘分配額。
      </p>
      <div className="mt-3 grid gap-2">
        {warnings.map((warning) => (
          <div
            key={`${warning.vehicle_id}-${warning.role}`}
            className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-warning/10 p-3 text-sm"
          >
            <span><b>{warning.stock_no}</b>：{warning.message}</span>
            <Link to="/salary/profiles" className="flex min-h-11 items-center font-medium text-primary">
              {warning.correction.label}
            </Link>
          </div>
        ))}
      </div>
    </section>
  )
}

function SettlementCard({
  settlement,
  tiers,
  draft,
  commissionState,
  expanded,
  onToggle,
  onAddAdjustment,
  onDeleteItem,
}: {
  settlement: SalarySettlement
  tiers: CommissionPlanTier[]
  draft: boolean
  commissionState: CommissionState
  expanded: boolean
  onToggle: () => void
  onAddAdjustment: () => void
  onDeleteItem: (itemId: number) => void
}) {
  return (
    <section className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold text-fg">{settlement.user.name}</h2>
          <p className="mt-1 text-xs leading-5 text-fg-muted">
            獎金級距計入台數 {settlement.eligible_sales_count} 台・適用 {formatPercent(settlement.sales_bonus_bps)}・
            {nextTierText(settlement, tiers, commissionState, draft)}
          </p>
        </div>
        <div className="text-right">
          <p className="text-xs text-fg-muted">實發薪資</p>
          <p className="text-xl font-bold tabular-nums">{formatCurrency(settlement.net_pay)}</p>
        </div>
      </div>
      <SettlementMetrics settlement={settlement} />
      <div className="mt-4 grid gap-2 sm:flex sm:flex-wrap">
        <button onClick={onToggle} className="min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm font-medium text-primary">
          {expanded ? '收合明細' : '展開薪資與車輛獎金明細'}
        </button>
        {draft && <button onClick={onAddAdjustment} className="min-h-11 rounded-lg border border-border-strong px-3 py-2 text-sm text-fg-muted">新增加扣項</button>}
      </div>
      {expanded && <SettlementItems settlement={settlement} draft={draft} onDeleteItem={onDeleteItem} />}
    </section>
  )
}

function SettlementMetrics({ settlement }: { settlement: SalarySettlement }) {
  return (
    <div className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
      <Metric label="底薪" value={settlement.base_salary} />
      <Metric label="固定津貼" value={settlement.fixed_allowance} />
      <Metric label="收車獎金" value={settlement.purchase_bonus_total} />
      <Metric label="賣車獎金" value={settlement.sales_bonus_total} />
      <Metric label="其他加給" value={settlement.manual_addition_total} />
      <Metric label="勞保扣款" value={settlement.labor_insurance_deduction} />
      <Metric label="健保扣款" value={settlement.health_insurance_deduction} />
      <Metric label="其他扣款" value={settlement.manual_deduction_total} />
    </div>
  )
}

function SettlementItems({ settlement, draft, onDeleteItem }: {
  settlement: SalarySettlement
  draft: boolean
  onDeleteItem: (itemId: number) => void
}) {
  return (
    <div className="mt-3">
      <div className="grid gap-2 sm:hidden">
        {settlement.items.map((item) => (
          <article key={item.id} className="rounded-lg border border-border bg-surface-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <span className="font-medium">{itemLabels[item.type] ?? item.type}</span>
              <span className="font-semibold tabular-nums">{formatCurrency(item.amount)}</span>
            </div>
            <div className="mt-2 break-words text-fg-muted">
              {item.vehicle ? (
                <Link className="inline-flex min-h-11 items-center font-medium text-primary" to={`/vehicles/${item.vehicle.id}`}>
                  {item.vehicle.stock_no} {item.vehicle.brand} {item.vehicle.model}
                </Link>
              ) : item.description}
            </div>
            {draft && item.type.startsWith('manual_') && (
              <button onClick={() => onDeleteItem(item.id)} className="mt-2 min-h-11 text-error">刪除</button>
            )}
          </article>
        ))}
      </div>
      <table className="hidden min-w-full text-sm sm:table">
        <tbody className="divide-y divide-border">
          {settlement.items.map((item) => (
            <tr key={item.id}>
              <td className="py-2">{itemLabels[item.type] ?? item.type}</td>
              <td className="py-2">
                {item.vehicle ? (
                  <Link className="inline-flex min-h-11 items-center text-primary" to={`/vehicles/${item.vehicle.id}`}>
                    {item.vehicle.stock_no} {item.vehicle.brand} {item.vehicle.model}
                  </Link>
                ) : item.description}
              </td>
              <td className="py-2 text-right tabular-nums">{formatCurrency(item.amount)}</td>
              <td className="py-2 text-right">
                {draft && item.type.startsWith('manual_') && (
                  <button onClick={() => onDeleteItem(item.id)} className="min-h-11 text-error">刪除</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function AdjustmentModal({ form, busy, onChange, onSave, onCancel }: {
  form: AdjustmentForm
  busy: boolean
  onChange: (form: AdjustmentForm) => void
  onSave: () => void
  onCancel: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-3 sm:p-4">
      <div className="max-h-[calc(100dvh-1.5rem)] w-full max-w-md overflow-y-auto rounded-2xl border border-border bg-surface p-4 shadow-lg sm:p-6">
        <h2 className="font-semibold">新增手動加扣項</h2>
        <div className="mt-4 grid gap-3">
          <label className="text-sm">
            類型 <span className="text-error">*</span>
            <select
              value={form.type}
              onChange={(event) => onChange({ ...form, type: event.target.value as AdjustmentForm['type'] })}
              className="form-control-touch mt-1 w-full rounded-lg border border-border-strong px-3 py-2"
            >
              <option value="manual_addition">其他加給</option>
              <option value="manual_deduction">其他扣款</option>
            </select>
          </label>
          <label className="text-sm">
            金額 <span className="text-error">*</span>
            <input
              type="number"
              min="1"
              value={form.amount}
              onChange={(event) => onChange({ ...form, amount: event.target.value })}
              className="form-control-touch mt-1 w-full rounded-lg border border-border-strong px-3 py-2"
            />
          </label>
          <label className="text-sm">
            說明 <span className="text-error">*</span>
            <input
              maxLength={255}
              value={form.description}
              onChange={(event) => onChange({ ...form, description: event.target.value })}
              className="form-control-touch mt-1 w-full rounded-lg border border-border-strong px-3 py-2"
            />
          </label>
        </div>
        <div className="mt-4 grid grid-cols-2 gap-2">
          <button
            disabled={busy || !form.amount || !form.description}
            onClick={onSave}
            className="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm text-primary-fg disabled:opacity-50"
          >
            新增
          </button>
          <button onClick={onCancel} className="min-h-11 rounded-lg border border-border-strong px-4 py-2 text-sm">取消</button>
        </div>
      </div>
    </div>
  )
}

function correctionPath(anomaly: SalaryAnomaly): string | null {
  if (anomaly.correction.action === 'money_entry_source_review') return null
  if (anomaly.correction.action === 'commission_attribution') return '/vehicles/commission-attribution'
  if (anomaly.correction.action === 'salary_period') {
    const periodIds = anomaly.context.salary_period_ids as number[] | undefined
    return periodIds?.[0] ? `/salary/periods/${periodIds[0]}` : `/vehicles/${anomaly.vehicle_id}`
  }
  return `/vehicles/${anomaly.vehicle_id}`
}

function nextTierText(
  settlement: SalarySettlement,
  tiers: CommissionPlanTier[],
  commissionState: CommissionState,
  draft: boolean,
): string {
  if (!draft) return '級距已隨本月結算鎖定'
  if (commissionState === 'disabled') return '目前未啟用獎金'
  if (commissionState === 'loading') return '獎金啟用狀態載入中...'
  if (commissionState === 'unavailable') return '無法確認獎金啟用狀態'
  const next = tiers.find((tier) => tier.min_sales_count > settlement.eligible_sales_count)
  return next
    ? `再 ${next.min_sales_count - settlement.eligible_sales_count} 台升至 ${formatPercent(next.sales_bonus_bps)}`
    : '已達最高級距'
}

function commissionStateFor(
  userId: number,
  commissionEnabledByUser: Record<number, boolean> | null,
  commissionDisabledAgentIds: Set<number>,
  commissionProfilesFailed: boolean,
): CommissionState {
  if (commissionDisabledAgentIds.has(userId)) return 'disabled'
  if (commissionEnabledByUser === null) return commissionProfilesFailed ? 'unavailable' : 'loading'
  if (commissionEnabledByUser[userId] === true) return 'enabled'
  if (commissionEnabledByUser[userId] === false) return 'disabled'
  return 'unavailable'
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-2 text-2xl font-bold tabular-nums">{value}</p>
    </div>
  )
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg bg-surface-2 p-3">
      <p className="text-xs text-fg-muted">{label}</p>
      <p className="font-semibold tabular-nums">{formatCurrency(value)}</p>
    </div>
  )
}
