import { describe, expect, it } from 'vitest'
import { vehicleCardVisibility } from './vehicleCardPresentation'

describe('vehicle card role field contract', () => {
  it.each(['admin', 'manager'] as const)('%s can see floor price but never purchase price or gross profit', (role) => {
    expect(vehicleCardVisibility(role)).toEqual({
      floorPrice: true,
      purchasePrice: false,
      grossProfit: false,
    })
  })

  it('sales cannot see floor price or sensitive financial fields', () => {
    expect(vehicleCardVisibility('sales')).toEqual({
      floorPrice: false,
      purchasePrice: false,
      grossProfit: false,
    })
  })

  it('fails closed without a recognized role', () => {
    expect(vehicleCardVisibility(undefined)).toEqual({
      floorPrice: false,
      purchasePrice: false,
      grossProfit: false,
    })
  })
})
