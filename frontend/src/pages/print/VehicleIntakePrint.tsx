import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { getVehiclePrintIntake } from '../../api/vehicles'
import type { VehiclePrintIntakeResponse } from '../../types/vehicle'
import { vehicleStatusLabels } from '../../utils/vehicleStatus'
import './print.css'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

function formatCurrency(amount: number | null): string {
  return amount === null ? '-' : currencyFormatter.format(amount)
}

export function VehicleIntakePrint() {
  const { id } = useParams<{ id: string }>()
  const vehicleId = Number(id)
  const [data, setData] = useState<VehiclePrintIntakeResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    getVehiclePrintIntake(vehicleId)
      .then(setData)
      .catch(() => setError('車輛資料載入失敗'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (error) return <p className="p-6 text-sm text-red-600">{error}</p>
  if (!data) return <p className="p-6 text-sm text-gray-500">載入中...</p>

  const { vehicle, printed_at: printedAt } = data

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

      <div className="print-title">車輛建檔資料</div>
      <div className="print-meta">列印日期：{new Date(printedAt).toLocaleString('zh-TW')}</div>

      <div className="print-section">
        <h2>基本資料</h2>
        <div className="print-grid">
          <div className="print-row">
            <span>庫存編號</span>
            <span>{vehicle.stock_no}</span>
          </div>
          <div className="print-row">
            <span>狀態</span>
            <span>{vehicleStatusLabels[vehicle.status]}</span>
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
            <span>年式</span>
            <span>{vehicle.year ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>車牌</span>
            <span>{vehicle.license_plate ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>VIN</span>
            <span>{vehicle.vin ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>里程</span>
            <span>{vehicle.mileage_km ? `${vehicle.mileage_km} km` : '-'}</span>
          </div>
          <div className="print-row">
            <span>顏色</span>
            <span>{vehicle.color ?? '-'}</span>
          </div>
        </div>
      </div>

      <div className="print-section">
        <h2>採購資料</h2>
        <div className="print-grid">
          <div className="print-row">
            <span>買入日期</span>
            <span>{vehicle.purchase_date ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>買入來源</span>
            <span>{vehicle.purchase_source_type ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>原車主 / 供應商</span>
            <span>{vehicle.seller_name ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>聯絡電話</span>
            <span>{vehicle.seller_phone ?? '-'}</span>
          </div>
          <div className="print-row">
            <span>收購價</span>
            <span>{formatCurrency(vehicle.purchase_price)}</span>
          </div>
        </div>
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
