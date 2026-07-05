import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { getVehiclePrintClosing } from '../../api/vehicles'
import type { VehiclePrintClosingResponse } from '../../types/vehicle'
import './print.css'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null): string {
  return amount === null ? '-' : currencyFormatter.format(amount)
}

export function VehicleClosingPrint() {
  const { id } = useParams<{ id: string }>()
  const vehicleId = Number(id)
  const [data, setData] = useState<VehiclePrintClosingResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    getVehiclePrintClosing(vehicleId)
      .then(setData)
      .catch(() => setError('車輛資料載入失敗'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (error) return <p className="p-6 text-sm text-red-600">{error}</p>
  if (!data) return <p className="p-6 text-sm text-gray-500">載入中...</p>

  const { vehicle, summary, money_entries: moneyEntries, printed_at: printedAt } = data
  const incomeEntries = moneyEntries.filter((entry) => entry.direction === 'income')
  const expenseEntries = moneyEntries.filter((entry) => entry.direction === 'expense')

  return (
    <div className="print-page">
      <div className="print-toolbar">
        <button
          onClick={() => window.print()}
          className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
          列印
        </button>
      </div>

      <div className="print-title">成交結案收支明細</div>
      <div className="print-meta">列印日期：{new Date(printedAt).toLocaleString('zh-TW')}</div>

      <div className="print-section">
        <h2>車輛基本資料</h2>
        <div className="print-grid">
          <div className="print-row">
            <span>庫存編號</span>
            <span>{vehicle.stock_no}</span>
          </div>
          <div className="print-row">
            <span>廠牌</span>
            <span>{vehicle.brand}</span>
          </div>
          <div className="print-row">
            <span>車型</span>
            <span>{vehicle.model}</span>
          </div>
          <div className="print-row">
            <span>車牌</span>
            <span>{vehicle.license_plate ?? '-'}</span>
          </div>
        </div>
      </div>

      <div className="print-section">
        <h2>買入資料</h2>
        <div className="print-grid">
          <div className="print-row">
            <span>買入日期</span>
            <span>{vehicle.purchase_date ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>原車主 / 供應商</span>
            <span>{vehicle.seller_name ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>收購價</span>
            <span>{formatCurrency(vehicle.purchase_price)}</span>
          </div>
        </div>
      </div>

      <div className="print-section">
        <h2>銷售資料</h2>
        <div className="print-grid">
          <div className="print-row">
            <span>成交價</span>
            <span>{formatCurrency(vehicle.sold_price)}</span>
          </div>
          <div className="print-row">
            <span>買方姓名</span>
            <span>{vehicle.buyer_name ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>買方電話</span>
            <span>{vehicle.buyer_phone ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>成交日期</span>
            <span>{vehicle.sold_at ? vehicle.sold_at.slice(0, 10) : '-'}</span>
          </div>
        </div>
      </div>

      <div className="print-section">
        <h2>收入明細</h2>
        <table className="print-table">
          <thead>
            <tr>
              <th>日期</th>
              <th>分類</th>
              <th>金額</th>
              <th>說明</th>
            </tr>
          </thead>
          <tbody>
            {incomeEntries.length === 0 && (
              <tr>
                <td colSpan={4}>無</td>
              </tr>
            )}
            {incomeEntries.map((entry) => (
              <tr key={entry.id}>
                <td>{entry.entry_date}</td>
                <td>{entry.category}</td>
                <td>{formatCurrency(entry.amount)}</td>
                <td>{entry.description ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="print-section">
        <h2>支出明細</h2>
        <table className="print-table">
          <thead>
            <tr>
              <th>日期</th>
              <th>分類</th>
              <th>金額</th>
              <th>說明</th>
            </tr>
          </thead>
          <tbody>
            {expenseEntries.length === 0 && (
              <tr>
                <td colSpan={4}>無</td>
              </tr>
            )}
            {expenseEntries.map((entry) => (
              <tr key={entry.id}>
                <td>{entry.entry_date}</td>
                <td>{entry.category}</td>
                <td>{formatCurrency(entry.amount)}</td>
                <td>{entry.description ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="print-totals">
        <span>收入合計：{formatCurrency(summary.income_total)}</span>
        <span>支出合計：{formatCurrency(summary.expense_total)}</span>
        <span>單車毛利：{formatCurrency(summary.gross_profit)}</span>
      </div>

      <div className="print-section">
        <h2>備註</h2>
        <p>{vehicle.notes ?? '-'}</p>
      </div>

      <div className="print-signoff">
        <div>經辦人簽名</div>
        <div>主管簽名</div>
      </div>
    </div>
  )
}
