import type { UserRole } from '../types/user'
import { canViewFinancials } from './permissions'

export interface VehicleCardVisibility {
  floorPrice: boolean
  purchasePrice: false
  grossProfit: false
}

export function vehicleCardVisibility(role: UserRole | undefined): VehicleCardVisibility {
  return {
    floorPrice: canViewFinancials(role),
    purchasePrice: false,
    grossProfit: false,
  }
}
