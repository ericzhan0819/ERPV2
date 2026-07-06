import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listCustomers } from '../../api/customers'
import type { Customer, CustomerListMeta, CustomerType } from '../../types/customer'

const typeOptions: { value: CustomerType | ''; label: string }[] = [
  { value: '', label: '全部類型' },
  { value: 'buyer', label: '買方' },
  { value: 'seller', label: '賣方' },
  { value: 'both', label: '買賣方' },
  { value: 'other', label: '其他' },
]

const typeLabels: Record<CustomerType, string> = {
  buyer: '買方',
  seller: '賣方',
  both: '買賣方',
  other: '其他',
}

export function CustomerList() {
  const [customers, setCustomers] = useState<Customer[]>([])
  const [meta, setMeta] = useState<CustomerListMeta | null>(null)
  const [search, setSearch] = useState('')
  const [customerType, setCustomerType] = useState<CustomerType | ''>('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setLoading(true)
    setError(null)
    listCustomers({ search: search || undefined, customer_type: customerType || undefined, page })
      .then((response) => {
        setCustomers(response.data)
        setMeta(response.meta)
      })
      .catch(() => setError('客戶列表載入失敗'))
      .finally(() => setLoading(false))
  }, [search, customerType, page])

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">客戶管理</h1>
          <p className="mt-1 text-sm text-fg-muted">買方、賣方、同行、介紹客等基本資料</p>
        </div>
        <Link
          to="/customers/create"
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
        >
          新增客戶
        </Link>
      </div>

      <div className="flex flex-wrap gap-3">
        <input
          type="text"
          placeholder="搜尋姓名 / 電話 / Line ID"
          value={search}
          onChange={(e) => {
            setPage(1)
            setSearch(e.target.value)
          }}
          className="w-72 rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        />
        <select
          value={customerType}
          onChange={(e) => {
            setPage(1)
            setCustomerType(e.target.value as CustomerType | '')
          }}
          className="rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
        >
          {typeOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">姓名</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">電話</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">類型</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">來源</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">建立日期</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-fg-muted">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && customers.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-fg-muted">
                  尚無符合條件的客戶
                </td>
              </tr>
            )}
            {!loading &&
              customers.map((customer) => (
                <tr key={customer.id} className="hover:bg-surface-2">
                  <td className="px-4 py-3">
                    <Link to={`/customers/${customer.id}`} className="font-medium text-fg hover:underline">
                      {customer.name}
                    </Link>
                  </td>
                  <td className="px-4 py-3">{customer.phone ?? '-'}</td>
                  <td className="px-4 py-3">{typeLabels[customer.customer_type]}</td>
                  <td className="px-4 py-3">{customer.source ?? '-'}</td>
                  <td className="px-4 py-3">{customer.created_at ? customer.created_at.slice(0, 10) : '-'}</td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-fg-muted">
          <span>
            第 {meta.current_page} / {meta.last_page} 頁，共 {meta.total} 筆
          </span>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={meta.current_page <= 1}
              className="rounded-lg border border-border-strong px-3 py-1.5 disabled:opacity-50"
            >
              上一頁
            </button>
            <button
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={meta.current_page >= meta.last_page}
              className="rounded-lg border border-border-strong px-3 py-1.5 disabled:opacity-50"
            >
              下一頁
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
