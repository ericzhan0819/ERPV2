import type { VehicleStatus } from '../types/vehicle'

export const vehicleStatusLabels: Record<VehicleStatus, string> = {
  preparing: '整備中',
  listed: '上架中',
  reserved: '保留中',
  sold: '已售出',
  cancelled: '取消 / 退車',
}

export const vehicleStatusBadgeClasses: Record<VehicleStatus, string> = {
  preparing: 'bg-amber-100 text-amber-800',
  listed: 'bg-blue-100 text-blue-800',
  reserved: 'bg-purple-100 text-purple-800',
  sold: 'bg-green-100 text-green-800',
  cancelled: 'bg-gray-200 text-gray-700',
}
