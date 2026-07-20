import { useEffect, useId, useRef, useState } from 'react'
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
  const [activeIndex, setActiveIndex] = useState(-1)
  const containerRef = useRef<HTMLDivElement>(null)
  const latestRequestId = useRef(0)
  const nameInputId = useId()
  const phoneInputId = useId()
  const listboxId = useId()
  const trimmedQuery = name.trim()
  const showListbox = open && trimmedQuery !== ''
  const activeCustomer = activeIndex >= 0 ? results[activeIndex] : undefined

  useEffect(() => {
    const requestId = ++latestRequestId.current

    if (!open || trimmedQuery === '') {
      setResults([])
      setActiveIndex(-1)
      setLoading(false)
      return
    }

    setLoading(true)
    const handle = window.setTimeout(() => {
      listCustomers({ search: trimmedQuery, per_page: 20 })
        .then((response) => {
          if (requestId !== latestRequestId.current) return
          setResults(response.data)
          setActiveIndex(-1)
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
        setActiveIndex(-1)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function handleNameChange(nextName: string) {
    // 修改已選客戶的姓名代表改回自由輸入；保留電話，避免使用者需要重新輸入。
    onChange({ customerId: '', name: nextName, phone })
    setOpen(true)
    setActiveIndex(-1)
  }

  function handleSelect(customer: Customer) {
    onChange({
      customerId: String(customer.id),
      name: customer.name,
      phone: customer.phone ?? '',
    })
    setOpen(false)
    setActiveIndex(-1)
  }

  function handleUseAsNewCustomer() {
    onChange({ customerId: '', name, phone })
    setOpen(false)
    setActiveIndex(-1)
  }

  function handleNameKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setOpen(true)
      if (results.length > 0) setActiveIndex((index) => (index + 1) % results.length)
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setOpen(true)
      if (results.length > 0) setActiveIndex((index) => (index <= 0 ? results.length - 1 : index - 1))
      return
    }

    if (event.key === 'Enter' && open && activeIndex >= 0 && results[activeIndex]) {
      event.preventDefault()
      handleSelect(results[activeIndex])
      return
    }

    if (event.key === 'Escape' && open) {
      event.preventDefault()
      setOpen(false)
      setActiveIndex(-1)
    }
  }

  return (
    <div ref={containerRef} className="contents">
      <div className="relative">
        <label htmlFor={nameInputId} className="mb-1 block text-sm font-medium text-fg-muted">
          {nameLabel}
          {required && <span className="text-error"> *</span>}
        </label>
        <input
          id={nameInputId}
          type="text"
          required={required}
          autoComplete="off"
          value={name}
          onFocus={() => setOpen(true)}
          onChange={(event) => handleNameChange(event.target.value)}
          onKeyDown={handleNameKeyDown}
          placeholder="輸入姓名搜尋既有客戶"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={showListbox}
          aria-controls={showListbox ? listboxId : undefined}
          aria-activedescendant={showListbox && activeCustomer ? `${listboxId}-option-${activeCustomer.id}` : undefined}
          className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />

        {customerId && (
          <div className="mt-1 flex items-center justify-between gap-2 text-xs">
            <span className="text-success">已選擇既有客戶，電話已自動帶入</span>
            <button
              type="button"
              onClick={handleUseAsNewCustomer}
              className="shrink-0 rounded-sm text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface"
            >
              改為新客戶
            </button>
          </div>
        )}

        {showListbox && (
          <div
            id={listboxId}
            role="listbox"
            aria-label={`${nameLabel}搜尋結果`}
            aria-busy={loading}
            className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-border-strong bg-surface shadow-lg"
          >
            {loading && <div role="option" aria-disabled="true" aria-selected="false" className="px-3 py-2 text-sm text-fg-muted">搜尋中...</div>}
            {!loading && results.length === 0 && (
              <div role="option" aria-disabled="true" aria-selected="false" className="px-3 py-2 text-sm text-fg-muted">
                查無相符客戶，繼續填寫電話後會自動建立
              </div>
            )}
            {!loading && results.map((customer, index) => (
              <button
                id={`${listboxId}-option-${customer.id}`}
                type="button"
                role="option"
                aria-selected={index === activeIndex}
                tabIndex={-1}
                key={customer.id}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => handleSelect(customer)}
                className={`block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-surface-2 ${index === activeIndex ? 'bg-surface-2' : ''}`}
              >
                <span className="block font-medium text-fg">{customer.name}</span>
                <span className="block text-xs text-fg-muted">{customer.phone || '尚未填寫電話'}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      <div>
        <label htmlFor={phoneInputId} className="mb-1 block text-sm font-medium text-fg-muted">
          {phoneLabel}
          {!customerId && required && <span className="text-error"> *</span>}
        </label>
        <input
          id={phoneInputId}
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
