import type { MoneyDirection, MoneyEntryApprovalStatus } from '../types/moneyEntry'
import type { VehicleStatus } from '../types/vehicle'
import { categoriesForDirection } from './moneyEntryCategory'

export const vehicleStatuses: VehicleStatus[] = ['preparing', 'listed', 'reserved', 'sold', 'cancelled']
export const defaultVehicleStatuses: VehicleStatus[] = ['preparing', 'listed', 'reserved']

const moneyDirections = new Set<MoneyDirection>(['income', 'expense'])
const approvalStatuses = new Set<MoneyEntryApprovalStatus>(['approved', 'pending', 'rejected'])

export interface VehicleListFilters {
  search: string
  statuses: VehicleStatus[]
  isPreparationCompleted?: boolean
  soldMonth: string
  page: number
}

export interface MoneyEntryListFilters {
  search: string
  direction: MoneyDirection | ''
  category: string
  cashAccountId: number | null
  vehicleId: number | null
  dateFrom: string
  dateTo: string
  approvalStatus: MoneyEntryApprovalStatus | ''
  page: number
}

function parsePositiveInteger(value: string | null): number | null {
  if (!value || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function parsePage(value: string | null): number {
  return parsePositiveInteger(value) ?? 1
}

function isBusinessDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value)
}

export function hasDefaultVehicleStatuses(statuses: VehicleStatus[]): boolean {
  return (
    statuses.length === defaultVehicleStatuses.length &&
    defaultVehicleStatuses.every((status) => statuses.includes(status))
  )
}

export function hasActiveVehicleListFilters(filters: VehicleListFilters): boolean {
  return Boolean(
    filters.search ||
      filters.isPreparationCompleted !== undefined ||
      filters.soldMonth ||
      !hasDefaultVehicleStatuses(filters.statuses),
  )
}

export function hasActiveMoneyEntryListFilters(filters: MoneyEntryListFilters): boolean {
  return Boolean(
    filters.search ||
      filters.direction ||
      filters.category ||
      filters.cashAccountId ||
      filters.vehicleId ||
      filters.dateFrom ||
      filters.dateTo ||
      filters.approvalStatus,
  )
}

export function parseVehicleListFilters(params: URLSearchParams): VehicleListFilters {
  const rawStatuses = params
    .getAll('status')
    .flatMap((value) => value.split(','))
    .map((value) => value.trim())
  const statuses = vehicleStatuses.filter((status) => rawStatuses.includes(status))
  const preparation = params.get('is_preparation_completed')

  return {
    search: params.get('search') ?? '',
    statuses: statuses.length > 0 ? statuses : [...defaultVehicleStatuses],
    soldMonth: params.get('sold_month') ?? '',
    ...(preparation === 'true'
      ? { isPreparationCompleted: true }
      : preparation === 'false'
        ? { isPreparationCompleted: false }
        : {}),
    page: parsePage(params.get('page')),
  }
}

export function serializeVehicleListFilters(filters: VehicleListFilters): URLSearchParams {
  const params = new URLSearchParams()
  const search = filters.search.trim()
  const statuses = vehicleStatuses.filter((status) => filters.statuses.includes(status))

  if (search) params.set('search', search)
  if (!hasDefaultVehicleStatuses(statuses)) params.set('status', statuses.join(','))
  if (filters.isPreparationCompleted !== undefined) {
    params.set('is_preparation_completed', String(filters.isPreparationCompleted))
  }
  if (filters.soldMonth.trim()) params.set('sold_month', filters.soldMonth.trim())
  if (filters.page > 1) params.set('page', String(filters.page))

  return params
}

export function parseMoneyEntryListFilters(params: URLSearchParams): MoneyEntryListFilters {
  const direction = params.get('direction')
  const parsedDirection = moneyDirections.has(direction as MoneyDirection) ? (direction as MoneyDirection) : ''
  const category = params.get('category') ?? ''
  const approval = params.get('approval') ?? params.get('approval_status')
  const dateFrom = params.get('date_from') ?? ''
  const dateTo = params.get('date_to') ?? ''

  return {
    search: params.get('search') ?? '',
    direction: parsedDirection,
    category: categoriesForDirection(parsedDirection).includes(category) ? category : '',
    cashAccountId: parsePositiveInteger(params.get('cash_account_id')),
    vehicleId: parsePositiveInteger(params.get('vehicle_id')),
    dateFrom: isBusinessDate(dateFrom) ? dateFrom : '',
    dateTo: isBusinessDate(dateTo) ? dateTo : '',
    approvalStatus: approvalStatuses.has(approval as MoneyEntryApprovalStatus)
      ? (approval as MoneyEntryApprovalStatus)
      : '',
    page: parsePage(params.get('page')),
  }
}

export function serializeMoneyEntryListFilters(filters: MoneyEntryListFilters): URLSearchParams {
  const params = new URLSearchParams()
  const search = filters.search.trim()

  if (search) params.set('search', search)
  if (filters.direction) params.set('direction', filters.direction)
  if (filters.category) params.set('category', filters.category)
  if (filters.cashAccountId) params.set('cash_account_id', String(filters.cashAccountId))
  if (filters.vehicleId) params.set('vehicle_id', String(filters.vehicleId))
  if (filters.dateFrom) params.set('date_from', filters.dateFrom)
  if (filters.dateTo) params.set('date_to', filters.dateTo)
  if (filters.approvalStatus) params.set('approval', filters.approvalStatus)
  if (filters.page > 1) params.set('page', String(filters.page))

  return params
}
