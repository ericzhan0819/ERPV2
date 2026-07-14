import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getDashboardSummary } from '../api/dashboard'
import type { DashboardSummary } from '../types/dashboard'
import { useAuth } from '../hooks/useAuth'
import { canManageVehicles, canRunSalesFlow, canViewFinancials } from '../utils/permissions'
import { listSalaryPeriods } from '../api/salaryPeriods'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number): string {
  return currencyFormatter.format(amount)
}

interface CardProps {
  label: string
  value: string
}

function Card({ label, value }: CardProps) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5 shadow-sm">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-2 text-3xl font-bold text-fg tabular-nums">{value}</p>
    </div>
  )
}

const quickActions = [
  { to: '/vehicles/create', label: '新增買入車輛' },
  { to: '/vehicles', label: '車輛列表' },
  { to: '/customers', label: '客戶列表' },
  { to: '/customers/create', label: '新增客戶' },
  { to: '/money-entries', label: '收支紀錄' },
  { to: '/money-entries/create?direction=income', label: '新增一般收入' },
  { to: '/money-entries/create?direction=expense', label: '新增一般支出' },
]

export function Dashboard() {
  const { user } = useAuth()
  const canViewFinance = canViewFinancials(user?.role)
  const canManage = canManageVehicles(user?.role)
  const canRunSales = canRunSalesFlow(user?.role)
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [monthlySalary, setMonthlySalary] = useState<number | null>(null)

  useEffect(() => {
    getDashboardSummary()
      .then(setSummary)
      .catch(() => setError('儀表板資料載入失敗'))
  }, [])

  useEffect(() => {
    if (user?.role !== 'admin') return
    const currentMonth = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' }).slice(0, 7)
    listSalaryPeriods().then((periods) => setMonthlySalary(periods.find((period) => period.period_month === currentMonth)?.net_pay_total ?? null)).catch(() => {})
  }, [user?.role])

  if (error) {
    return <p className="text-sm text-error">{error}</p>
  }

  if (!summary) {
    return <p className="text-sm text-fg-muted">載入中...</p>
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-fg">總覽</h1>
        <p className="mt-1 text-sm text-fg-muted">資金與車輛即時概況</p>
      </div>

      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {canViewFinance && summary.cash_balance !== undefined && (
          <Card label="現金餘額" value={formatCurrency(summary.cash_balance)} />
        )}
        {canViewFinance && summary.bank_balance !== undefined && (
          <Card label="主要銀行餘額" value={formatCurrency(summary.bank_balance)} />
        )}
        {canViewFinance && summary.other_balance !== undefined && (
          <Card label="其他帳戶餘額" value={formatCurrency(summary.other_balance)} />
        )}
        {canViewFinance && summary.total_funds !== undefined && (
          <Card label="資金合計" value={formatCurrency(summary.total_funds)} />
        )}
        {canViewFinance && summary.monthly_income !== undefined && (
          <Card label="本月收入" value={formatCurrency(summary.monthly_income)} />
        )}
        {canViewFinance && summary.monthly_expense !== undefined && (
          <Card label="本月支出" value={formatCurrency(summary.monthly_expense)} />
        )}
        {canViewFinance && summary.monthly_net_flow !== undefined && (
          <Card label="本月淨流入" value={formatCurrency(summary.monthly_net_flow)} />
        )}
        {user?.role === 'admin' && monthlySalary !== null && <Card label="本月預估薪資" value={formatCurrency(monthlySalary)} />}
        <Card label="本月成交台數" value={`${summary.monthly_sold_count} 台`} />
        <Card label="整備中車輛" value={`${summary.vehicle_counts.preparing} 台`} />
        <Card label="上架中車輛" value={`${summary.vehicle_counts.listed} 台`} />
        <Card label="保留中車輛" value={`${summary.vehicle_counts.reserved} 台`} />
        <Card label="已售出車輛" value={`${summary.vehicle_counts.sold} 台`} />
      </section>

      <section>
        <h2 className="text-sm font-medium text-fg-muted">快捷操作</h2>
        <div className="mt-3 flex flex-wrap gap-3">
          {quickActions
            .filter((action) => {
              if (action.to === '/vehicles/create') return canManage
              if (action.to.startsWith('/customers') || action.to.startsWith('/money-entries')) return canRunSales
              return true
            })
            .map((action) => (
              <Link
                key={action.to}
                to={action.to}
                className="rounded-lg border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
              >
                {action.label}
              </Link>
            ))}
        </div>
      </section>
    </div>
  )
}
