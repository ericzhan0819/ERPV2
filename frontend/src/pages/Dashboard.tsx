import { useEffect, useState, type ComponentType } from 'react'
import { Link } from 'react-router-dom'
import {
  ArrowDownToLine,
  ArrowRight,
  Banknote,
  CarFront,
  CircleDollarSign,
  ClipboardCheck,
  HandCoins,
  ReceiptText,
  TrendingUp,
  UserPlus,
  Wrench,
} from 'lucide-react'
import { getDashboardSummary } from '../api/dashboard'
import { DashboardTrendChart } from '../components/DashboardTrendChart'
import type { DashboardSummary } from '../types/dashboard'
import { useAuth } from '../hooks/useAuth'
import {
  dashboardActions,
  dashboardMoneyEntriesLink,
  dashboardSoldVehiclesLink,
  dashboardVisibility,
  type DashboardActionId,
} from '../utils/dashboardPresentation'

const currencyFormatter = new Intl.NumberFormat('zh-TW', {
  style: 'currency',
  currency: 'TWD',
  maximumFractionDigits: 0,
})
const numberFormatter = new Intl.NumberFormat('zh-TW')

const actionIcons: Record<DashboardActionId, ComponentType<{ className?: string; 'aria-hidden'?: boolean }>> = {
  create_vehicle: CarFront,
  report_money_entry: ReceiptText,
  create_customer: UserPlus,
  report_preparation_expense: Wrench,
}

interface ActionLinkProps {
  to: string
  label: string
  icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
  primary?: boolean
}

function ActionLink({ to, label, icon: Icon, primary = false }: ActionLinkProps) {
  return (
    <Link
      to={to}
      className={`flex min-h-11 items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg ${
        primary
          ? 'border-primary bg-primary text-primary-fg hover:bg-primary-hover'
          : 'border-border-strong bg-surface text-fg hover:bg-surface-2'
      }`}
    >
      <Icon aria-hidden className="h-4 w-4" />
      {label}
    </Link>
  )
}

interface KpiCardProps {
  to: string
  label: string
  value: string
  description: string
  icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
}

function KpiCard({ to, label, value, description, icon: Icon }: KpiCardProps) {
  return (
    <Link
      to={to}
      className="group flex min-h-40 flex-col rounded-xl border border-border bg-surface p-5 shadow-sm transition-colors hover:border-border-strong hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
    >
      <div className="flex items-start justify-between gap-3">
        <span className="rounded-lg bg-surface-2 p-2 text-primary group-hover:bg-surface">
          <Icon aria-hidden className="h-5 w-5" />
        </span>
        <ArrowRight aria-hidden className="h-4 w-4 text-fg-subtle transition-transform group-hover:translate-x-0.5" />
      </div>
      <p className="mt-4 text-sm font-medium text-fg-muted">{label}</p>
      <p className="mt-1 text-3xl font-bold text-fg tabular-nums">{value}</p>
      <p className="mt-2 text-xs text-fg-muted">{description}</p>
    </Link>
  )
}

function SectionState({ message }: { message: string }) {
  return <div className="rounded-xl border border-border bg-surface p-6 text-sm text-fg-muted">{message}</div>
}

export function Dashboard() {
  const { user } = useAuth()
  const visibility = dashboardVisibility(user?.role)
  const actions = dashboardActions(user?.role)
  const canViewFinance = visibility.canViewFinancials
  const canManage = visibility.canManageVehicles
  const canApproveMoney = visibility.canApproveMoneyEntries
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [refreshToken, setRefreshToken] = useState(0)

  useEffect(() => {
    let active = true
    setError(null)
    getDashboardSummary()
      .then((response) => {
        if (active) setSummary(response)
      })
      .catch(() => {
        if (active) setError('儀表板資料載入失敗，請稍後再試。')
      })
    return () => {
      active = false
    }
  }, [refreshToken])

  const stateMessage = error ?? (!summary ? '正在載入資料…' : null)
  const work = summary?.work_overview
  const business = summary?.business_overview
  const trendCount = 1 + (canViewFinance && summary?.trends.gross_profit ? 1 : 0) +
    (canViewFinance && summary?.trends.cash_balance ? 1 : 0)
  const trendGridClass = trendCount === 1
    ? 'xl:grid-cols-1'
    : trendCount === 2
      ? 'xl:grid-cols-2'
      : 'xl:grid-cols-3'

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-6">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-fg">營運總覽</h1>
          <p className="mt-1 text-sm text-fg-muted">掌握今日工作、本月經營與近 30 天趨勢</p>
        </div>
        {actions.length > 0 && (
          <nav aria-label="快捷操作" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {actions.map((action) => (
              <ActionLink
                key={action.id}
                to={action.to}
                label={action.label}
                icon={actionIcons[action.id]}
                primary={action.id === 'create_vehicle' || (!canManage && action.id === 'report_money_entry')}
              />
            ))}
          </nav>
        )}
      </header>

      {error && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-error/30 bg-surface p-4 text-sm text-error">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => {
              setSummary(null)
              setRefreshToken((token) => token + 1)
            }}
            className="min-h-11 rounded-lg border border-error/40 px-4 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            重新載入
          </button>
        </div>
      )}

      <section aria-labelledby="work-overview-title">
        <div className="mb-4">
          <h2 id="work-overview-title" className="text-lg font-semibold text-fg">工作概況</h2>
          <p className="mt-1 text-sm text-fg-muted">點選卡片前往對應工作區處理</p>
        </div>
        {stateMessage ? (
          <SectionState message={stateMessage} />
        ) : work && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard to="/vehicles?status=preparing&is_preparation_completed=false" label="待整備" value={`${numberFormatter.format(work.preparation_pending_count)} 台`} description="整備尚未完成" icon={Wrench} />
            <KpiCard to="/vehicles?status=preparing&is_preparation_completed=true" label="待上架" value={`${numberFormatter.format(work.listing_pending_count)} 台`} description="整備完成，等待上架" icon={ClipboardCheck} />
            <KpiCard to="/vehicles?status=reserved" label="待交車" value={`${numberFormatter.format(work.delivery_pending_count)} 台`} description="已保留，等待完成交車" icon={CarFront} />
            {canApproveMoney && work.pending_money_entry_count !== undefined && (
              <KpiCard to="/money-entries?approval=pending" label="待審核收支" value={`${numberFormatter.format(work.pending_money_entry_count)} 筆`} description="等待核准或駁回" icon={ReceiptText} />
            )}
          </div>
        )}
      </section>

      <section aria-labelledby="business-overview-title">
        <div className="mb-4">
          <h2 id="business-overview-title" className="text-lg font-semibold text-fg">經營概況</h2>
          <p className="mt-1 text-sm text-fg-muted">月份 KPI 依完整當月口徑；現金為正式帳面餘額</p>
        </div>
        {stateMessage ? (
          <SectionState message={stateMessage} />
        ) : business && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <KpiCard to="/vehicles?status=preparing,listed,reserved" label="在庫數" value={`${numberFormatter.format(business.inventory_count)} 台`} description="整備中、上架中與保留中" icon={CarFront} />
            {canViewFinance && business.cash_balance !== undefined && (
              <KpiCard to="/cash-accounts" label="現金帳面餘額" value={currencyFormatter.format(business.cash_balance)} description="所有已核准現金收支" icon={Banknote} />
            )}
            {canViewFinance && business.sold_month && business.monthly_income !== undefined && (
              <KpiCard to={dashboardMoneyEntriesLink('income', business.sold_month)} label="本月收入" value={currencyFormatter.format(business.monthly_income)} description="完整當月・僅計已核准" icon={ArrowDownToLine} />
            )}
            {canViewFinance && business.sold_month && business.monthly_expense !== undefined && (
              <KpiCard to={dashboardMoneyEntriesLink('expense', business.sold_month)} label="本月支出" value={currencyFormatter.format(business.monthly_expense)} description="完整當月・僅計已核准" icon={HandCoins} />
            )}
            {canViewFinance && business.sold_month && business.monthly_gross_profit !== undefined && (
              <KpiCard to={dashboardSoldVehiclesLink(business.sold_month)} label="本月毛利" value={currencyFormatter.format(business.monthly_gross_profit)} description="完整當月成交・僅計已核准收支" icon={TrendingUp} />
            )}
            {canViewFinance && business.sold_month && business.monthly_sold_count !== undefined && (
              <KpiCard to={dashboardSoldVehiclesLink(business.sold_month)} label="本月成交" value={`${numberFormatter.format(business.monthly_sold_count)} 台`} description="依完整當月成交日期統計" icon={CircleDollarSign} />
            )}
          </div>
        )}
      </section>

      <section aria-labelledby="trends-title">
        <div className="mb-4">
          <h2 id="trends-title" className="text-lg font-semibold text-fg">趨勢分析</h2>
          <p className="mt-1 text-sm text-fg-muted">近 30 個連續日，包含今天且截至今日</p>
        </div>
        {stateMessage ? (
          <SectionState message={stateMessage} />
        ) : summary && (
          <div className={`grid min-w-0 grid-cols-1 gap-4 ${trendGridClass}`}>
            <DashboardTrendChart
              title="近 30 天成交量"
              description="依車輛成交日期每日統計"
              unit="台"
              points={summary.trends.sales_count.map((point) => ({ date: point.date, value: point.count }))}
              formatValue={(value) => `${numberFormatter.format(value)} 台`}
            />
            {canViewFinance && summary.trends.gross_profit && (
              <DashboardTrendChart
                title="近 30 天毛利"
                description="依成交日歸屬，僅計已核准收支"
                unit="新台幣"
                points={summary.trends.gross_profit.map((point) => ({ date: point.date, value: point.amount }))}
                formatValue={(value) => currencyFormatter.format(value)}
              />
            )}
            {canViewFinance && summary.trends.cash_balance && (
              <DashboardTrendChart
                title="現金變化"
                description="現金帳戶每日期末帳面餘額"
                unit="新台幣"
                points={summary.trends.cash_balance.map((point) => ({ date: point.date, value: point.balance }))}
                formatValue={(value) => currencyFormatter.format(value)}
              />
            )}
          </div>
        )}
      </section>
    </div>
  )
}
