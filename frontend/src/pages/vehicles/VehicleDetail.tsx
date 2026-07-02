import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { getVehicle } from '../../api/vehicles'
import type { VehicleDetailResponse } from '../../types/vehicle'
import { vehicleStatusBadgeClasses, vehicleStatusLabels } from '../../utils/vehicleStatus'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null): string {
  return amount === null ? '-' : currencyFormatter.format(amount)
}

interface InfoRowProps {
  label: string
  value: string
}

function InfoRow({ label, value }: InfoRowProps) {
  return (
    <div className="flex justify-between border-b border-gray-100 py-2 text-sm last:border-0">
      <span className="text-gray-500">{label}</span>
      <span className="font-medium text-gray-900">{value}</span>
    </div>
  )
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
      <h2 className="mb-2 text-sm font-semibold text-gray-700">{title}</h2>
      {children}
    </div>
  )
}

export function VehicleDetail() {
  const { id } = useParams<{ id: string }>()
  const [detail, setDetail] = useState<VehicleDetailResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    getVehicle(Number(id))
      .then(setDetail)
      .catch(() => setError('車輛資料載入失敗'))
  }, [id])

  if (error) {
    return <p className="text-sm text-red-600">{error}</p>
  }

  if (!detail) {
    return <p className="text-sm text-gray-500">載入中...</p>
  }

  const { vehicle, summary, money_entries: moneyEntries } = detail

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-semibold text-gray-900">{vehicle.stock_no}</h1>
            <span className={`rounded-full px-2 py-1 text-xs font-medium ${vehicleStatusBadgeClasses[vehicle.status]}`}>
              {vehicleStatusLabels[vehicle.status]}
            </span>
          </div>
          <p className="mt-1 text-sm text-gray-500">
            {vehicle.brand} {vehicle.model}
          </p>
        </div>
        <Link
          to="/vehicles"
          className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
        >
          返回列表
        </Link>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Panel title="基本資料">
          <InfoRow label="庫存編號" value={vehicle.stock_no} />
          <InfoRow label="狀態" value={vehicleStatusLabels[vehicle.status]} />
          <InfoRow label="廠牌" value={vehicle.brand} />
          <InfoRow label="車型" value={vehicle.model} />
          <InfoRow label="年式" value={vehicle.year?.toString() ?? '-'} />
          <InfoRow label="車牌" value={vehicle.license_plate ?? '-'} />
          <InfoRow label="VIN" value={vehicle.vin ?? '-'} />
          <InfoRow label="里程" value={vehicle.mileage_km ? `${vehicle.mileage_km} km` : '-'} />
          <InfoRow label="顏色" value={vehicle.color ?? '-'} />
          <InfoRow label="備註" value={vehicle.notes ?? '-'} />
        </Panel>

        <Panel title="採購資料">
          <InfoRow label="買入日期" value={vehicle.purchase_date ?? '-'} />
          <InfoRow label="買入來源" value={vehicle.purchase_source_type ?? '-'} />
          <InfoRow label="原車主 / 供應商" value={vehicle.seller_name ?? '-'} />
          <InfoRow label="收購價" value={formatCurrency(vehicle.purchase_price)} />
        </Panel>

        <Panel title="銷售資料">
          <InfoRow label="開價" value={formatCurrency(vehicle.asking_price)} />
          <InfoRow label="底價" value={formatCurrency(vehicle.floor_price)} />
          <InfoRow label="成交價" value={formatCurrency(vehicle.sold_price)} />
          <InfoRow label="買方姓名" value={vehicle.buyer_name ?? '-'} />
          <InfoRow label="買方電話" value={vehicle.buyer_phone ?? '-'} />
        </Panel>
      </div>

      <Panel title="單車收支摘要">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl bg-gray-50 p-4">
            <p className="text-xs text-gray-500">單車收入合計</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{formatCurrency(summary.income_total)}</p>
          </div>
          <div className="rounded-xl bg-gray-50 p-4">
            <p className="text-xs text-gray-500">單車支出合計</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{formatCurrency(summary.expense_total)}</p>
          </div>
          <div className="rounded-xl bg-gray-50 p-4">
            <p className="text-xs text-gray-500">單車毛利</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{formatCurrency(summary.gross_profit)}</p>
          </div>
        </div>
      </Panel>

      <Panel title="單車收支明細">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left font-medium text-gray-500">日期</th>
                <th className="px-3 py-2 text-left font-medium text-gray-500">收支</th>
                <th className="px-3 py-2 text-left font-medium text-gray-500">分類</th>
                <th className="px-3 py-2 text-left font-medium text-gray-500">金額</th>
                <th className="px-3 py-2 text-left font-medium text-gray-500">資金帳戶</th>
                <th className="px-3 py-2 text-left font-medium text-gray-500">說明</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {moneyEntries.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-3 py-4 text-center text-gray-500">
                    尚無收支紀錄
                  </td>
                </tr>
              )}
              {moneyEntries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-3 py-2">{entry.entry_date}</td>
                  <td className="px-3 py-2">{entry.direction === 'income' ? '收入' : '支出'}</td>
                  <td className="px-3 py-2">{entry.category}</td>
                  <td className="px-3 py-2">{formatCurrency(entry.amount)}</td>
                  <td className="px-3 py-2">{entry.cash_account?.name ?? '-'}</td>
                  <td className="px-3 py-2">{entry.description ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}
