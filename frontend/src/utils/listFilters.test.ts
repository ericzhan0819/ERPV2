import { describe, expect, it } from 'vitest'
import {
  defaultVehicleStatuses,
  hasActiveMoneyEntryListFilters,
  hasActiveVehicleListFilters,
  parseMoneyEntryListFilters,
  parseVehicleListFilters,
  serializeMoneyEntryListFilters,
  serializeVehicleListFilters,
} from './listFilters'

describe('vehicle list filter contract', () => {
  it('restores defaults and normalizes invalid URL values', () => {
    const filters = parseVehicleListFilters(new URLSearchParams(
      'status=unknown&is_preparation_completed=1&page=0',
    ))

    expect(filters).toEqual({
      search: '',
      statuses: defaultVehicleStatuses,
      soldMonth: '',
      page: 1,
    })
    expect(hasActiveVehicleListFilters(filters)).toBe(false)
  })

  it('parses combined filters and serializes them in canonical form', () => {
    const filters = parseVehicleListFilters(new URLSearchParams(
      'search=Camry&status=sold,listed&is_preparation_completed=false&sold_month=2026-07&page=3',
    ))

    expect(filters).toEqual({
      search: 'Camry',
      statuses: ['listed', 'sold'],
      isPreparationCompleted: false,
      soldMonth: '2026-07',
      page: 3,
    })
    expect(hasActiveVehicleListFilters(filters)).toBe(true)
    expect(serializeVehicleListFilters(filters).toString()).toBe(
      'search=Camry&status=listed%2Csold&is_preparation_completed=false&sold_month=2026-07&page=3',
    )
  })

  it('omits default statuses and the first page from a shareable URL', () => {
    expect(serializeVehicleListFilters({
      search: '  ',
      statuses: [...defaultVehicleStatuses],
      soldMonth: '',
      page: 1,
    }).toString()).toBe('')
  })
})

describe('money entry list filter contract', () => {
  it('enforces direction, category, approval and positive id whitelists', () => {
    const filters = parseMoneyEntryListFilters(new URLSearchParams(
      'direction=income&category=薪資+%2F+佣金&approval_status=pending&cash_account_id=-1&vehicle_id=12',
    ))

    expect(filters.direction).toBe('income')
    expect(filters.category).toBe('')
    expect(filters.approvalStatus).toBe('pending')
    expect(filters.cashAccountId).toBeNull()
    expect(filters.vehicleId).toBe(12)
  })

  it('round trips active money entry filters using the canonical approval key', () => {
    const filters = parseMoneyEntryListFilters(new URLSearchParams(
      'search=廣告&direction=expense&category=廣告&cash_account_id=3&vehicle_id=8&date_from=2026-07-01&date_to=2026-07-31&approval=approved&page=2',
    ))

    expect(hasActiveMoneyEntryListFilters(filters)).toBe(true)
    expect(serializeMoneyEntryListFilters(filters).toString()).toBe(
      'search=%E5%BB%A3%E5%91%8A&direction=expense&category=%E5%BB%A3%E5%91%8A&cash_account_id=3&vehicle_id=8&date_from=2026-07-01&date_to=2026-07-31&approval=approved&page=2',
    )
  })
})
