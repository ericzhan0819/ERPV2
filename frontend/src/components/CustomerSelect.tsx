import { useEffect, useRef, useState } from 'react'
import { listCustomers } from '../api/customers'
import type { Customer } from '../types/customer'

// 每次輸入都向 API 搜尋，避免只預載第一頁後，舊客戶因清單變大而無法選取。
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
  // 每次 effect 執行就更新請求編號，讓較慢的舊請求即使晚回來，也不能覆蓋目前關鍵字的結果。
  const latestRequestId = useRef(0)

  useEffect(() => {
    // 即使選單關閉也要讓既有請求失效，避免下次開啟時短暫顯示過期結果而選到錯誤客戶。
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
