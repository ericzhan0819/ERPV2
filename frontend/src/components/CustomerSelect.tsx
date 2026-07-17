import { useEffect, useRef, useState } from 'react'
import { listCustomers } from '../api/customers'
import type { Customer } from '../types/customer'

interface CustomerSelectValue {
  customerId: string
  name: string
  phone: string
}

interface CustomerSelectProps extends CustomerSelectValue {
  nameLabel: string
  phoneLabel?: string
  required?: boolean
  onChange: (value: CustomerSelectValue) => void
}

/**
 * 姓名輸入即時搜尋既有客戶；選取後帶入電話與 customer_id。
 * 未選既有客戶時，姓名與電話維持自由輸入，後端會在車輛流程中自動建立客戶。
 */
export function CustomerSelect({
  customerId,
  name,
  phone,
  nameLabel,
  phoneLabel = '聯絡電話',
  required = false,
  onChange,
}: CustomerSelectProps) {
  const [results, setResults] = useState<Customer[]>([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const latestRequestId = useRef(0)
  const trimmedQuery = name.trim()

  useEffect(() => {
    const requestId = ++latestRequestId.current

    if (!open || trimmedQuery === '') {
      setResults([])
      setLoading(false)
      return
    }

    setLoading(true)
    const handle = window.setTimeout(() => {
      listCustomers({ search: trimmedQuery, per_page: 20 })
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

    return () => window.clearTimeout(handle)
  }, [open, trimmedQuery])

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function handleNameChange(nextName: string) {
    // 修改已選客戶的姓名代表改回自由輸入；保留電話，避免使用者需要重新輸入。
    onChange({ customerId: '', name: nextName, phone })
    setOpen(true)
  }

  function handleSelect(customer: Customer) {
    onChange({
      customerId: String(customer.id),
      name: customer.name,
      phone: customer.phone ?? '',
    })
    setOpen(false)
  }

  function handleUseAsNewCustomer() {
    onChange({ customerId: '', name, phone })
    setOpen(false)
  }

  return (
    <div ref={containerRef} className="contents">
      <div className="relative">
        <label className="mb-1 block text-sm font-medium text-fg-muted">
          {nameLabel}
          {required && <span className="text-error"> *</span>}
        </label>
        <input
          type="text"
          required={required}
          autoComplete="off"
          value={name}
          onFocus={() => setOpen(true)}
          onChange={(event) => handleNameChange(event.target.value)}
          placeholder="輸入姓名搜尋既有客戶"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={open}
          className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />

        {customerId && (
          <div className="mt-1 flex items-center justify-between gap-2 text-xs">
            <span className="text-success">已選擇既有客戶，電話已自動帶入</span>
            <button type="button" onClick={handleUseAsNewCustomer} className="shrink-0 text-primary hover:underline">
              改為新客戶
            </button>
          </div>
        )}

        {open && trimmedQuery !== '' && (
          <div className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-border-strong bg-surface shadow-lg">
            {loading && <div className="px-3 py-2 text-sm text-fg-muted">搜尋中...</div>}
            {!loading && results.length === 0 && (
              <div className="px-3 py-2 text-sm text-fg-muted">
                查無相符客戶，繼續填寫電話後會自動建立
              </div>
            )}
            {!loading && results.map((customer) => (
              <button
                type="button"
                key={customer.id}
                onClick={() => handleSelect(customer)}
                className="block w-full px-3 py-2 text-left text-sm hover:bg-surface-2"
              >
                <span className="block font-medium text-fg">{customer.name}</span>
                <span className="block text-xs text-fg-muted">{customer.phone || '尚未填寫電話'}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      <div>
        <label className="mb-1 block text-sm font-medium text-fg-muted">
          {phoneLabel}
          {!customerId && required && <span className="text-error"> *</span>}
        </label>
        <input
          type="tel"
          required={required && !customerId}
          readOnly={Boolean(customerId)}
          value={phone}
          onChange={(event) => onChange({ customerId: '', name, phone: event.target.value })}
          placeholder={customerId ? '由既有客戶資料帶入' : '未選既有客戶時請手動輸入'}
          className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30 read-only:bg-surface-2 read-only:text-fg-muted"
        />
      </div>
    </div>
  )
}
