import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from './client'
import { updateVehiclePurchasePrice, updateVehiclePublicDescription, updateVehicleSalesPricing } from './vehicles'
import type { Vehicle } from '../types/vehicle'

vi.mock('./client', () => ({ apiClient: { get: vi.fn(), patch: vi.fn() } }))

const staleVehicle = {
  id: 7, brand: '舊廠牌', model: '舊型號', license_plate: 'OLD-1234', vin: 'OLD-VIN',
} as Vehicle

const operations = [
  {
    name: 'sales pricing',
    run: () => updateVehicleSalesPricing(staleVehicle, { asking_price: 530000, floor_price: null }),
    expectedChanges: { asking_price: 530000, floor_price: null },
  },
  {
    name: 'purchase price',
    run: () => updateVehiclePurchasePrice(staleVehicle, 400000),
    expectedChanges: { purchase_price: 400000 },
  },
  {
    name: 'public description',
    run: () => updateVehiclePublicDescription(staleVehicle, { public_description: '更新介紹' }),
    expectedChanges: { public_description: '更新介紹' },
  },
]

const identities = [
  {
    name: 'updated plate and VIN',
    latest: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234', vin: 'NEW-VIN' },
    expected: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234', vin: 'NEW-VIN' },
  },
  {
    name: 'null plate',
    latest: { brand: '新廠牌', model: '新型號', license_plate: null, vin: 'NEW-VIN' },
    expected: { brand: '新廠牌', model: '新型號', vin: 'NEW-VIN' },
  },
  {
    name: 'null VIN',
    latest: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234', vin: null },
    expected: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234' },
  },
  {
    name: 'empty plate',
    latest: { brand: '新廠牌', model: '新型號', license_plate: '', vin: 'NEW-VIN' },
    expected: { brand: '新廠牌', model: '新型號', vin: 'NEW-VIN' },
  },
  {
    name: 'empty VIN',
    latest: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234', vin: '' },
    expected: { brand: '新廠牌', model: '新型號', license_plate: 'NEW-1234' },
  },
]

describe.each(operations)('$name identity refresh', ({ run, expectedChanges }) => {
  beforeEach(() => vi.resetAllMocks())

  it.each(identities)('waits for the latest identity and preserves $name', async ({ latest, expected }) => {
    let finishRead!: (value: unknown) => void
    vi.mocked(apiClient.get).mockImplementation(() => new Promise((resolve) => { finishRead = resolve }))
    const saved = { ...staleVehicle, ...latest, ...expectedChanges }
    vi.mocked(apiClient.patch).mockResolvedValue({ data: { data: saved } })

    const pending = run()
    expect(apiClient.get).toHaveBeenCalledExactlyOnceWith('/api/vehicles/7')
    expect(apiClient.patch).not.toHaveBeenCalled()
    finishRead({ data: { vehicle: { ...staleVehicle, ...latest } } })

    expect(await pending).toEqual(saved)
    expect(apiClient.patch).toHaveBeenCalledExactlyOnceWith('/api/vehicles/7', {
      ...expected,
      ...expectedChanges,
    })
  })

  it('does not write stale data when the fresh read fails', async () => {
    const error = new Error('offline')
    vi.mocked(apiClient.get).mockRejectedValue(error)
    await expect(run()).rejects.toBe(error)
    expect(apiClient.patch).not.toHaveBeenCalled()
  })
})
