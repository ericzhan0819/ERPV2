import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { createCashAccount, deleteCashAccount, listCashAccountBalances, updateCashAccount } from '../../api/cashAccounts'
import type { CashAccountBalance, CashAccountPayload, CashAccountType } from '../../types/cashAccount'

const currencyFormatter = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })

const typeLabels: Record<CashAccountType, string> = {
  cash: '現金',
  bank: '銀行',
  other: '其他',
}

interface AccountFormState {
  name: string
  type: CashAccountType
  opening_balance: string
  is_active: boolean
}

const emptyForm: AccountFormState = { name: '', type: 'cash', opening_balance: '0', is_active: true }

export function CashAccountList() {
  const [accounts, setAccounts] = useState<CashAccountBalance[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [creating, setCreating] = useState(false)
  const [createForm, setCreateForm] = useState<AccountFormState>(emptyForm)
  const [createError, setCreateError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState<AccountFormState>(emptyForm)
  const [editError, setEditError] = useState<string | null>(null)

  function loadAccounts() {
    setLoading(true)
    setError(null)
    listCashAccountBalances()
      .then(setAccounts)
      .catch(() => setError('資金帳戶載入失敗'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadAccounts()
  }, [])

  function extractErrorMessage(err: unknown, fallback: string): string {
    if (isAxiosError(err)) {
      const data = err.response?.data
      const firstFieldError = data?.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : undefined
      return firstFieldError ?? data?.message ?? fallback
    }
    return fallback
  }

  async function handleCreateSubmit(event: FormEvent) {
    event.preventDefault()
    setCreateError(null)

    if (!createForm.name.trim()) {
      setCreateError('請輸入帳戶名稱')
      return
    }
    const openingBalance = Number(createForm.opening_balance)
    if (!Number.isInteger(openingBalance) || openingBalance < 0) {
      setCreateError('期初餘額必須為 0 或正整數')
      return
    }

    const payload: CashAccountPayload = {
      name: createForm.name.trim(),
      type: createForm.type,
      opening_balance: openingBalance,
      is_active: createForm.is_active,
    }

    setSubmitting(true)
    try {
      await createCashAccount(payload)
      setCreating(false)
      setCreateForm(emptyForm)
      loadAccounts()
    } catch (err) {
      setCreateError(extractErrorMessage(err, '新增帳戶失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  function startEdit(account: CashAccountBalance) {
    setEditingId(account.id)
    setEditError(null)
    setEditForm({
      name: account.name,
      type: account.type,
      opening_balance: String(account.opening_balance),
      is_active: account.is_active,
    })
  }

  async function handleEditSubmit(event: FormEvent, id: number) {
    event.preventDefault()
    setEditError(null)

    if (!editForm.name.trim()) {
      setEditError('請輸入帳戶名稱')
      return
    }
    const openingBalance = Number(editForm.opening_balance)
    if (!Number.isInteger(openingBalance) || openingBalance < 0) {
      setEditError('期初餘額必須為 0 或正整數')
      return
    }

    const payload: CashAccountPayload = {
      name: editForm.name.trim(),
      type: editForm.type,
      opening_balance: openingBalance,
      is_active: editForm.is_active,
    }

    setSubmitting(true)
    try {
      await updateCashAccount(id, payload)
      setEditingId(null)
      loadAccounts()
    } catch (err) {
      setEditError(extractErrorMessage(err, '更新帳戶失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(account: CashAccountBalance) {
    if (!window.confirm(`確定要刪除帳戶「${account.name}」嗎？此操作無法復原。`)) {
      return
    }
    setError(null)
    try {
      await deleteCashAccount(account.id)
      loadAccounts()
    } catch (err) {
      setError(extractErrorMessage(err, '刪除帳戶失敗'))
    }
  }

  async function toggleActive(account: CashAccountBalance) {
    setError(null)
    try {
      await updateCashAccount(account.id, {
        name: account.name,
        type: account.type,
        opening_balance: account.opening_balance,
        is_active: !account.is_active,
      })
      loadAccounts()
    } catch (err) {
      setError(extractErrorMessage(err, '更新帳戶狀態失敗'))
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">資金帳戶</h1>
          <p className="mt-1 text-sm text-gray-500">現金／銀行／其他帳戶與即時餘額</p>
        </div>
        <button
          onClick={() => {
            setCreating((v) => !v)
            setCreateError(null)
            setCreateForm(emptyForm)
          }}
          className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
          {creating ? '取消新增' : '新增帳戶'}
        </button>
      </div>

      {creating && (
        <form onSubmit={handleCreateSubmit} className="max-w-2xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">帳戶名稱</label>
              <input
                type="text"
                required
                value={createForm.name}
                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">類型</label>
              <select
                value={createForm.type}
                onChange={(e) => setCreateForm((f) => ({ ...f, type: e.target.value as CashAccountType }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              >
                <option value="cash">{typeLabels.cash}</option>
                <option value="bank">{typeLabels.bank}</option>
                <option value="other">{typeLabels.other}</option>
              </select>
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">期初餘額</label>
              <input
                type="number"
                required
                min={0}
                value={createForm.opening_balance}
                onChange={(e) => setCreateForm((f) => ({ ...f, opening_balance: e.target.value }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={createForm.is_active}
                  onChange={(e) => setCreateForm((f) => ({ ...f, is_active: e.target.checked }))}
                />
                啟用中
              </label>
            </div>
          </div>

          {createError && <p className="mt-4 text-sm text-red-600">{createError}</p>}

          <div className="mt-6 flex gap-3">
            <button
              type="submit"
              disabled={submitting}
              className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
            >
              {submitting ? '建立中...' : '建立帳戶'}
            </button>
          </div>
        </form>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-500">帳戶名稱</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">類型</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">期初餘額</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">目前餘額</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">狀態</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-gray-500">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && accounts.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-gray-500">
                  尚無資金帳戶
                </td>
              </tr>
            )}
            {!loading &&
              accounts.map((account) =>
                editingId === account.id ? (
                  <tr key={account.id} className="bg-gray-50">
                    <td colSpan={6} className="px-4 py-4">
                      <form onSubmit={(e) => handleEditSubmit(e, account.id)} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                          <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">帳戶名稱</label>
                            <input
                              type="text"
                              required
                              value={editForm.name}
                              onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                            />
                          </div>
                          <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">類型</label>
                            <select
                              value={editForm.type}
                              onChange={(e) => setEditForm((f) => ({ ...f, type: e.target.value as CashAccountType }))}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                            >
                              <option value="cash">{typeLabels.cash}</option>
                              <option value="bank">{typeLabels.bank}</option>
                              <option value="other">{typeLabels.other}</option>
                            </select>
                          </div>
                          <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">期初餘額</label>
                            <input
                              type="number"
                              required
                              min={0}
                              value={editForm.opening_balance}
                              onChange={(e) => setEditForm((f) => ({ ...f, opening_balance: e.target.value }))}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                            />
                          </div>
                          <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                              <input
                                type="checkbox"
                                checked={editForm.is_active}
                                onChange={(e) => setEditForm((f) => ({ ...f, is_active: e.target.checked }))}
                              />
                              啟用中
                            </label>
                          </div>
                        </div>

                        {editError && <p className="text-sm text-red-600">{editError}</p>}

                        <div className="flex gap-3">
                          <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                          >
                            {submitting ? '儲存中...' : '儲存'}
                          </button>
                          <button
                            type="button"
                            onClick={() => setEditingId(null)}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                          >
                            取消
                          </button>
                        </div>
                      </form>
                    </td>
                  </tr>
                ) : (
                  <tr key={account.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{account.name}</td>
                    <td className="px-4 py-3">{typeLabels[account.type]}</td>
                    <td className="px-4 py-3">{currencyFormatter.format(account.opening_balance)}</td>
                    <td className="px-4 py-3">{currencyFormatter.format(account.current_balance)}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`rounded-full px-2 py-1 text-xs font-medium ${
                          account.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600'
                        }`}
                      >
                        {account.is_active ? '啟用中' : '已停用'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex gap-3">
                        <button onClick={() => startEdit(account)} className="text-sm font-medium text-gray-900 hover:underline">
                          編輯
                        </button>
                        <button onClick={() => toggleActive(account)} className="text-sm font-medium text-gray-600 hover:underline">
                          {account.is_active ? '停用' : '啟用'}
                        </button>
                        <button onClick={() => handleDelete(account)} className="text-sm font-medium text-red-600 hover:underline">
                          刪除
                        </button>
                      </div>
                    </td>
                  </tr>
                ),
              )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
