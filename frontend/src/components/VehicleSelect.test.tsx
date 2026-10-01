// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { getVehicle, listVehicles } from '../api/vehicles'
import type { Vehicle, VehicleListItem } from '../types/vehicle'
import { VehicleSelect } from './VehicleSelect'

vi.mock('../api/vehicles', () => ({ getVehicle: vi.fn(), listVehicles: vi.fn() }))
const oldVehicle = { id: 101, stock_no: 'OLD-101', brand: 'Toyota', model: 'Corolla', license_plate: 'ABC-101' } as VehicleListItem
const meta = { current_page: 1, last_page: 1, per_page: 25, total: 101 }
beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(listVehicles).mockResolvedValue({ data: [], meta })
  vi.mocked(getVehicle).mockResolvedValue({ vehicle: oldVehicle as Vehicle } as Awaited<ReturnType<typeof getVehicle>>)
})

it('searches server-side and selects an older vehicle outside the first 100', async () => {
  vi.mocked(listVehicles).mockImplementation(async (params) => ({ data: params.search === 'OLD-101' ? [oldVehicle] : [], meta }))
  function Form() {
    const [value, setValue] = useState<number | null>(null)
    return <VehicleSelect value={value} onChange={setValue} inventoryOnly />
  }
  render(<Form />)
  fireEvent.change(screen.getByLabelText('搜尋車輛'), { target: { value: 'OLD-101' } })
  await screen.findByRole('option', { name: /OLD-101/ })
  fireEvent.change(screen.getByLabelText('關聯車輛'), { target: { value: '101' } })
  expect((screen.getByLabelText('關聯車輛') as HTMLSelectElement).value).toBe('101')
  expect(listVehicles).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'OLD-101', status: ['preparing', 'listed', 'reserved'] }))
})

it('loads a URL-selected vehicle separately and retains it across searches', async () => {
  render(<VehicleSelect value={101} onChange={() => {}} />)
  await screen.findByRole('option', { name: /OLD-101/ })
  expect(getVehicle).toHaveBeenCalledWith(101)
  fireEvent.change(screen.getByLabelText('搜尋車輛'), { target: { value: 'other' } })
  await waitFor(() => expect(listVehicles).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'other' })))
  const select = screen.getByLabelText('關聯車輛') as HTMLSelectElement
  expect(select.selectedOptions[0].textContent).toContain('OLD-101')
})
