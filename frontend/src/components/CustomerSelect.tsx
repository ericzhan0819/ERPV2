import { useEffect, useRef, useState } from 'react'
import { listCustomers } from '../api/customers'
import type { Customer } from '../types/customer'

// A fixed-size preload (e.g. the first page of customers) silently hides
// every customer past that page once the dealership accumulates more than a
// page's worth — older customers would simply become impossible to select.
// This searches the API on every keystroke instead, so the full customer
// list stays reachable no matter how large it grows.
interface CustomerSelectProps {
  label: string
  value: string
  selectedLabel?: string
  onChange: (customerId: string, customer: Customer | null) => void
}

export function CustomerSelect({ label, value, selectedLabel, onChange }: CustomerSelectProps) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<Customer[]>([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  // A newer keystroke's request can resolve before an older, slower one — without
  // this guard the stale response would land last and overwrite the results with
  // data for a query the input no longer shows, letting the user pick a customer
  // that doesn't match what's on screen. The id is bumped synchronously on every
  // effect run — NOT inside the debounced callback, and NOT only while open — so
  // a request already in flight from the previous state is invalidated
  // immediately, even though its own fetch was sent before this state's 250ms
  // debounce has elapsed. Bumping it only when the debounced fetch fires would
  // leave that exact window unguarded: an in-flight older request could still
  // resolve and be accepted because the "latest" id hadn't moved on yet.
  const latestRequestId = useRef(0)

  useEffect(() => {
    // Bump on every run, including a close (open -> false): a request already in
    // flight when the selector closes must not be allowed to land afterwards and
    // silently repopulate `results` with data for a query that's no longer shown.
    // Otherwise, reopening later (even with the query unchanged) could briefly
    // render that stale list before a fresh fetch completes, and a click in that
    // window would attach the wrong customer.
    const requestId = ++latestRequestId.current

    if (!open) {
      setResults([])
      setLoading(false)
      return
    }

    setLoading(true)

    const handle = setTimeout(() => {
      listCustomers({ search: query || undefined, per_page: 20 })
        .then((response) => {
          if (requestId !== latestRequestId.current) return
          setResults(response.data)
        })
        .catch(() => {
          if (requestId !== latestRequestId.current) return
          setResults([])
        })
        .finally(() => {
          if (requestId !== latestRequestId.current) return
          setLoading(false)
        })
    }, 250)

    return () => clearTimeout(handle)
  }, [query, open])

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function handleSelect(customer: Customer) {
    onChange(String(customer.id), customer)
    setOpen(false)
    setQuery('')
  }

  function handleClear() {
    onChange('', null)
    setQuery('')
  }

  return (
    <div ref={containerRef} className="relative">
      <label className="mb-1 block text-sm font-medium text-fg-muted">{label}</label>
      {value && !open ? (
        <div className="flex items-center justify-between gap-2 rounded-lg border border-border-strong px-3 py-2 text-sm">
          <span className="truncate">{selectedLabel ?? `客戶 #${value}`}</span>
          <div className="flex shrink-0 gap-2">
            <button type="button" onClick={() => setOpen(true)} className="text-xs text-primary hover:underline">
              變更
            </button>
            <button type="button" onClick={handleClear} className="text-xs text-fg-muted hover:underline">
              清除
            </button>
          </div>
        </div>
      ) : (
        <>
          <input
            type="text"
            value={query}
            onFocus={() => setOpen(true)}
            onChange={(e) => {
              setQuery(e.target.value)
              setOpen(true)
            }}
            placeholder="輸入姓名 / 電話搜尋客戶"
            className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
          />
          {open && (
            <div className="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-border-strong bg-surface shadow-lg">
              {loading && <div className="px-3 py-2 text-sm text-fg-muted">搜尋中...</div>}
              {!loading && results.length === 0 && <div className="px-3 py-2 text-sm text-fg-muted">查無客戶</div>}
              {!loading &&
                results.map((customer) => (
                  <button
                    type="button"
                    key={customer.id}
                    onClick={() => handleSelect(customer)}
                    className="block w-full px-3 py-2 text-left text-sm hover:bg-surface-2"
                  >
                    {customer.name} {customer.phone ? `(${customer.phone})` : ''}
                  </button>
                ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}
