import { useId } from 'react'
import { useVehicleOptions } from '../hooks/useVehicleOptions'
import { DebouncedSearchInput } from './DebouncedSearchInput'
import { FormAlert } from './FormAlert'

export function VehicleSelect({ error, value, onChange, inventoryOnly = false, label = '關聯車輛', emptyLabel = '全部車輛' }: {
  error?: string
  value: number | null
  onChange: (value: number | null) => void
  inventoryOnly?: boolean
  label?: string
  emptyLabel?: string
}) {
  const id = useId()
  const state = useVehicleOptions(value, inventoryOnly)
  const options = [...state.options]
  if (state.selected?.id === value && !options.some((option) => option.id === value)) options.unshift(state.selected)
  const controlClass = 'min-h-11 w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-sm text-fg'
  return (
    <div className="flex min-w-0 flex-col gap-2">
      <DebouncedSearchInput id={`${id}-search`} label="搜尋車輛" placeholder="庫存編號 / 車牌 / 品牌 / 車型"
        value={state.search} onCommit={state.setSearch} className={controlClass} />
      <label htmlFor={id} className="text-sm font-medium text-fg-muted">{label}</label>
      <select aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined} id={id} value={value ?? ''} onChange={(event) => onChange(event.target.value ? Number(event.target.value) : null)} className={controlClass}>
        <option value="">{emptyLabel}</option>
        {value !== null && !options.some((option) => option.id === value) && <option value={value}>車輛 #{value}（{state.error ? '無法載入' : '載入中'}）</option>}
        {options.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.stock_no}（{vehicle.brand} {vehicle.model} · {vehicle.license_plate ?? '無車牌'}）</option>)}
      </select>
      {error && <p id={`${id}-error`} className="text-sm text-error">{error}</p>}
      {state.loading && <p role="status" className="text-sm text-fg-muted">車輛載入中...</p>}
      <FormAlert message={state.error} />
      {state.lastPage > 1 && <div className="flex items-center gap-3 text-sm">
        <button type="button" disabled={state.loading || state.page === 1} onClick={() => state.setPage(state.page - 1)} className="min-h-11 disabled:opacity-50">上一頁車輛</button>
        <span>{state.page} / {state.lastPage}</span>
        <button type="button" disabled={state.loading || state.page >= state.lastPage} onClick={() => state.setPage(state.page + 1)} className="min-h-11 disabled:opacity-50">下一頁車輛</button>
      </div>}
    </div>
  )
}
