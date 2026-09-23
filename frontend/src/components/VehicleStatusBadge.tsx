import type { VehicleStatus } from '../types/vehicle'
import { vehicleStatusBadgeClasses, vehicleStatusIcons, vehicleStatusLabels } from '../utils/vehicleStatus'

export function VehicleStatusBadge({ status }: { status: VehicleStatus }) {
  const Icon = vehicleStatusIcons[status]
  return (
    <span className={vehicleStatusBadgeClasses[status]}>
      <Icon size={12} />
      {vehicleStatusLabels[status]}
    </span>
  )
}
