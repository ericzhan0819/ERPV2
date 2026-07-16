import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { isAxiosError } from 'axios'
import {
  listCommissionAgentOptions,
  listPendingCommissionAttribution,
  updateCommissionAttribution,
} from '../../api/vehicles'
import type { CommissionAgent, Vehicle } from '../../types/vehicle'

function errorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    const errors = error.response?.data?.errors
    const first = errors ? Object.values(errors)[0] : null
    if (Array.isArray(first) && first[0]) return first[0]
    if (error.response?.data?.message) return error.response.data.message
  }
  return '更新獎金歸屬失敗，請稍後再試'
}

export function CommissionAttributionPending() {
  const [vehicles, setVehicles] = useState<Vehicle[]>([])
  const [agents, setAgents] = useState<CommissionAgent[]>([])
  const [selection, setSelection] = useState<Record<number, { purchase: string; sales: string }>>({})
  const [savingId, setSavingId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  function load() {
    setLoading(true)
    setError(null)
    Promise.all([listPendingCommissionAttribution(), listCommissionAgentOptions()])
      .then(([pending, options]) => {
        setVehicles(pending)
        setAgents(options)
        setSelection(Object.fromEntries(pending.map((vehicle) => [vehicle.id, {
          purchase: vehicle.purchase_agent_id?.toString() ?? '',
          sales: vehicle.sales_agent_id?.toString() ?? '',
        }])))
      })
      .catch(() => setError('待補獎金歸屬資料載入失敗'))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function save(vehicle: Vehicle) {
    const values = selection[vehicle.id]
    if (!values?.purchase || !values.sales) {
      setError('請完整指定收車人與賣車人')
      return
    }

    setSavingId(vehicle.id)
    setError(null)
    try {
      await updateCommissionAttribution(vehicle.id, {
        purchase_agent_id: Number(values.purchase),
        sales_agent_id: Number(values.sales),
      })
      load()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setSavingId(null)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-fg">待補獎金歸屬</h1>
          <p className="mt-1 text-sm text-fg-muted">補齊歷史已售車輛的實際收車人與賣車人</p>
        </div>
        <Link to="/vehicles" className="flex min-h-11 items-center rounded-lg border border-border-strong px-4 py-2 text-sm text-fg-muted hover:bg-surface-2">
          返回車輛列表
        </Link>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="grid gap-3 md:hidden" aria-live="polite">
        {loading && <StateCard message="載入中..." />}
        {!loading && vehicles.length === 0 && <StateCard message="目前沒有待補資料" />}
        {!loading && vehicles.map((vehicle) => {
          const values = selection[vehicle.id] ?? { purchase: '', sales: '' }
          return (
            <article key={vehicle.id} className="rounded-2xl border border-border bg-surface p-4 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <Link to={`/vehicles/${vehicle.id}`} className="inline-flex min-h-11 items-center font-medium text-fg hover:underline">{vehicle.stock_no}</Link>
                  <p className="break-words text-xs text-fg-muted">{vehicle.brand} {vehicle.model}</p>
                </div>
                <span className="text-sm text-fg-muted">成交日 {vehicle.sold_at?.slice(0, 10) ?? '-'}</span>
              </div>
              <div className="mt-4 grid gap-3">
                {(['purchase', 'sales'] as const).map((field) => (
                  <label key={field} className="text-sm text-fg-muted">
                    {field === 'purchase' ? '收車人' : '賣車人'} <span className="text-error">*</span>
                    <select
                      value={values[field]}
                      onChange={(event) => setSelection((current) => ({
                        ...current,
                        [vehicle.id]: { ...values, [field]: event.target.value },
                      }))}
                      className="mt-1 w-full rounded-lg border border-border-strong bg-surface px-3 py-2 text-fg"
                    >
                      <option value="">請選擇</option>
                      {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.name}</option>)}
                    </select>
                  </label>
                ))}
              </div>
              <button
                type="button"
                disabled={savingId === vehicle.id}
                onClick={() => save(vehicle)}
                className="mt-4 min-h-11 w-full rounded-lg bg-primary px-3 py-2 font-medium text-primary-fg disabled:opacity-50"
              >
                {savingId === vehicle.id ? '儲存中...' : '儲存歸屬'}
              </button>
            </article>
          )
        })}
      </div>

      <div className="hidden overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm md:block">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">車輛</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">成交日</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">收車人</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">賣車人</th>
              <th className="px-4 py-3 text-right font-medium text-fg-muted">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-fg-muted">載入中...</td></tr>
            )}
            {!loading && vehicles.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-8 text-center text-fg-muted">目前沒有待補資料</td></tr>
            )}
            {!loading && vehicles.map((vehicle) => {
              const values = selection[vehicle.id] ?? { purchase: '', sales: '' }
              return (
                <tr key={vehicle.id}>
                  <td className="px-4 py-3">
                    <Link to={`/vehicles/${vehicle.id}`} className="inline-flex min-h-11 items-center font-medium text-fg hover:underline">{vehicle.stock_no}</Link>
                    <div className="text-xs text-fg-muted">{vehicle.brand} {vehicle.model}</div>
                  </td>
                  <td className="px-4 py-3">{vehicle.sold_at?.slice(0, 10) ?? '-'}</td>
                  {(['purchase', 'sales'] as const).map((field) => (
                    <td key={field} className="px-4 py-3">
                      <select
                        aria-label={field === 'purchase' ? '收車人' : '賣車人'}
                        value={values[field]}
                        onChange={(event) => setSelection((current) => ({
                          ...current,
                          [vehicle.id]: { ...values, [field]: event.target.value },
                        }))}
                        className="min-w-36 rounded-lg border border-border-strong bg-surface px-3 py-2 text-sm text-fg"
                      >
                        <option value="">請選擇</option>
                        {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.name}</option>)}
                      </select>
                    </td>
                  ))}
                  <td className="px-4 py-3 text-right">
                    <button
                      type="button"
                      disabled={savingId === vehicle.id}
                      onClick={() => save(vehicle)}
                      className="min-h-11 rounded-lg bg-primary px-3 py-2 font-medium text-primary-fg disabled:opacity-50"
                    >
                      {savingId === vehicle.id ? '儲存中...' : '儲存歸屬'}
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function StateCard({ message }: { message: string }) {
  return <p className="rounded-2xl border border-border bg-surface px-4 py-8 text-center text-sm text-fg-muted">{message}</p>
}
