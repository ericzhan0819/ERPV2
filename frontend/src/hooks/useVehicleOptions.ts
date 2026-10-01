import { useEffect, useState } from 'react'
import { getVehicle, listVehicles } from '../api/vehicles'
import type { Vehicle, VehicleStatus } from '../types/vehicle'

export function useVehicleOptions(value: number | null, inventoryOnly: boolean) {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [options, setOptions] = useState<Vehicle[]>([])
  const [selected, setSelected] = useState<Vehicle | null>(null)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)
    listVehicles({ search: search || undefined, page, per_page: 25,
      ...(inventoryOnly ? { status: ['preparing', 'listed', 'reserved'] as VehicleStatus[] } : {}),
    }).then((response) => {
      if (!active) return
      setOptions(response.data)
      setLastPage(response.meta.last_page)
    }).catch(() => { if (active) setError('車輛選項載入失敗，請重新搜尋。') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [search, page, inventoryOnly])
  useEffect(() => {
    let active = true
    setSelected(null)
    if (value !== null) {
      getVehicle(value).then(({ vehicle }) => { if (active) setSelected(vehicle) })
        .catch(() => { if (active) setError('指定車輛載入失敗，請確認車輛是否存在。') })
    }
    return () => { active = false }
  }, [value])
  return { search, setSearch: (next: string) => { setSearch(next); setPage(1) }, page, setPage,
    options, selected, lastPage, loading, error }
}
