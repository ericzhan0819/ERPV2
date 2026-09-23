import { Bookmark, CircleCheck, Tag, Wrench, XCircle } from 'lucide-react'
import type { VehicleStatus } from '../types/vehicle'

export const vehicleStatusLabels: Record<VehicleStatus, string> = {
  preparing: '整備中',
  listed: '上架中',
  reserved: '保留中',
  sold: '已售出',
  cancelled: '取消 / 退車',
}

export const vehicleStatusBadgeClasses: Record<VehicleStatus, string> = {
  preparing: 'badge badge-amber',
  listed: 'badge badge-blue',
  reserved: 'badge badge-violet',
  sold: 'badge badge-emerald',
  cancelled: 'badge badge-slate',
}

export const vehicleStatusIcons: Record<VehicleStatus, typeof Wrench> = {
  preparing: Wrench,
  listed: Tag,
  reserved: Bookmark,
  sold: CircleCheck,
  cancelled: XCircle,
}
