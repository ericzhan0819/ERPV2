import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getDashboardSummary } from '../api/dashboard'
import type { DashboardSummary } from '../types/dashboard'

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
    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-gray-900">{value}</p>
    </div>
  )
}

const quickActions = [
  { to: '/vehicles/create', label: '新增買入車輛' },
  { to: '/money-entries/create?direction=income', label: '新增一般收入' },
  { to: '/money-entries/create?direction=expense', label: '新增一般支出' },
  { to: '/vehicles', label: '車輛列表' },
  { to: '/money-entries', label: '收支紀錄' },
]

export function Dashboard() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    getDashboardSummary()
      .then(setSummary)
      .catch(() => setError('儀表板資料載入失敗'))
  }, [])

  if (error) {
    return <p className="text-sm text-red-600">{error}</p>
  }

  if (!summary) {
    return <p className="text-sm text-gray-500">載入中...</p>
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">總覽</h1>
        <p className="mt-1 text-sm text-gray-500">資金與車輛即時概況</p>
      </div>

      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card label="現金餘額" value={formatCurrency(summary.cash_balance)} />
        <Card label="主要銀行餘額" value={formatCurrency(summary.bank_balance)} />
        <Card label="其他帳戶餘額" value={formatCurrency(summary.other_balance)} />
        <Card label="資金合計" value={formatCurrency(summary.total_funds)} />
        <Card label="本月收入" value={formatCurrency(summary.monthly_income)} />
        <Card label="本月支出" value={formatCurrency(summary.monthly_expense)} />
        <Card label="本月淨流入" value={formatCurrency(summary.monthly_net_flow)} />
        <Card label="本月成交台數" value={`${summary.monthly_sold_count} 台`} />
        <Card label="整備中車輛" value={`${summary.vehicle_counts.preparing} 台`} />
        <Card label="上架中車輛" value={`${summary.vehicle_counts.listed} 台`} />
        <Card label="保留中車輛" value={`${summary.vehicle_counts.reserved} 台`} />
        <Card label="已售出車輛" value={`${summary.vehicle_counts.sold} 台`} />
      </section>

      <section>
        <h2 className="text-sm font-medium text-gray-700">快捷操作</h2>
        <div className="mt-3 flex flex-wrap gap-3">
          {quickActions.map((action) => (
            <Link
              key={action.to}
              to={action.to}
              className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
            >
              {action.label}
            </Link>
          ))}
        </div>
      </section>
    </div>
  )
}
