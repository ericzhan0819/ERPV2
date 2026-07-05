import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { isAxiosError } from 'axios'
import {
  createCashAccount,
  deleteCashAccount,
  listCashAccountBalances,
  setCashAccountActive,
  updateCashAccount,
} from '../../api/cashAccounts'
import { useAuth } from '../../hooks/useAuth'
import { ActiveStatusBadge } from '../../components/ActiveStatusBadge'
import type { CashAccountBalance, CashAccountPayload, CashAccountType, CashAccountUpdatePayload } from '../../types/cashAccount'

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

interface EditFormState {
  name: string
  type: CashAccountType
  opening_balance: string
}

const emptyForm: AccountFormState = { name: '', type: 'cash', opening_balance: '0', is_active: true }
const emptyEditForm: EditFormState = { name: '', type: 'cash', opening_balance: '0' }

export function CashAccountList() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [accounts, setAccounts] = useState<CashAccountBalance[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [creating, setCreating] = useState(false)
  const [createForm, setCreateForm] = useState<AccountFormState>(emptyForm)
  const [createError, setCreateError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState<EditFormState>(emptyEditForm)
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

    const payload: CashAccountUpdatePayload = {
      name: editForm.name.trim(),
      type: editForm.type,
      opening_balance: openingBalance,
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
      await setCashAccountActive(account.id, !account.is_active)
      loadAccounts()
    } catch (err) {
      setError(extractErrorMessage(err, '更新帳戶狀態失敗'))
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">資金帳戶</h1>
          <p className="mt-1 text-sm text-fg-muted">現金／銀行／其他帳戶與即時餘額</p>
        </div>
        {isAdmin && (
          <button
            onClick={() => {
              setCreating((v) => !v)
              setCreateError(null)
              setCreateForm(emptyForm)
            }}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
          >
            {creating ? '取消新增' : '新增帳戶'}
          </button>
        )}
      </div>

      {isAdmin && creating && (
        <form onSubmit={handleCreateSubmit} className="max-w-2xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">帳戶名稱</label>
              <input
                type="text"
                required
                value={createForm.name}
                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">類型</label>
              <select
                value={createForm.type}
                onChange={(e) => setCreateForm((f) => ({ ...f, type: e.target.value as CashAccountType }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              >
                <option value="cash">{typeLabels.cash}</option>
                <option value="bank">{typeLabels.bank}</option>
                <option value="other">{typeLabels.other}</option>
              </select>
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">期初餘額</label>
              <input
                type="number"
                required
                min={0}
                value={createForm.opening_balance}
                onChange={(e) => setCreateForm((f) => ({ ...f, opening_balance: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm text-fg-muted">
                <input
                  type="checkbox"
                  checked={createForm.is_active}
                  onChange={(e) => setCreateForm((f) => ({ ...f, is_active: e.target.checked }))}
                />
                啟用中
              </label>
            </div>
          </div>

          {createError && <p className="mt-4 text-sm text-error">{createError}</p>}

          <div className="mt-6 flex gap-3">
            <button
              type="submit"
              disabled={submitting}
              className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
            >
              {submitting ? '建立中...' : '建立帳戶'}
            </button>
          </div>
        </form>
      )}

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">帳戶名稱</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">類型</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">期初餘額</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">目前餘額</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">狀態</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-fg-muted">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && accounts.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-fg-muted">
                  尚無資金帳戶
                </td>
              </tr>
            )}
            {!loading &&
              accounts.map((account) =>
                isAdmin && editingId === account.id ? (
                  <tr key={account.id} className="bg-surface-2">
                    <td colSpan={6} className="px-4 py-4">
                      <form onSubmit={(e) => handleEditSubmit(e, account.id)} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                          <div>
                            <label className="mb-1 block text-sm font-medium text-fg-muted">帳戶名稱</label>
                            <input
                              type="text"
                              required
                              value={editForm.name}
                              onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                            />
                          </div>
                          <div>
                            <label className="mb-1 block text-sm font-medium text-fg-muted">類型</label>
                            <select
                              value={editForm.type}
                              onChange={(e) => setEditForm((f) => ({ ...f, type: e.target.value as CashAccountType }))}
                              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                            >
                              <option value="cash">{typeLabels.cash}</option>
                              <option value="bank">{typeLabels.bank}</option>
                              <option value="other">{typeLabels.other}</option>
                            </select>
                          </div>
                          <div>
                            <label className="mb-1 block text-sm font-medium text-fg-muted">期初餘額</label>
                            <input
                              type="number"
                              required
                              min={0}
                              value={editForm.opening_balance}
                              onChange={(e) => setEditForm((f) => ({ ...f, opening_balance: e.target.value }))}
                              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                            />
                          </div>
                        </div>
                        <p className="text-xs text-fg-muted">啟用／停用請使用列表中的「停用／啟用」按鈕。</p>

                        {editError && <p className="text-sm text-error">{editError}</p>}

                        <div className="flex gap-3">
                          <button
                            type="submit"
                            disabled={submitting}
                            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
                          >
                            {submitting ? '儲存中...' : '儲存'}
                          </button>
                          <button
                            type="button"
                            onClick={() => setEditingId(null)}
                            className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
                          >
                            取消
                          </button>
                        </div>
                      </form>
                    </td>
                  </tr>
                ) : (
                  <tr key={account.id} className="hover:bg-surface-2">
                    <td className="px-4 py-3 font-medium text-fg">{account.name}</td>
                    <td className="px-4 py-3">{typeLabels[account.type]}</td>
                    <td className="px-4 py-3 tabular-nums">{currencyFormatter.format(account.opening_balance)}</td>
                    <td className="px-4 py-3 tabular-nums">{currencyFormatter.format(account.current_balance)}</td>
                    <td className="px-4 py-3">
                      <ActiveStatusBadge active={account.is_active} />
                    </td>
                    <td className="px-4 py-3">
                      {isAdmin ? (
                        <div className="flex gap-3">
                          <button onClick={() => startEdit(account)} className="text-sm font-medium text-fg hover:underline">
                            編輯
                          </button>
                          <button onClick={() => toggleActive(account)} className="text-sm font-medium text-fg-muted hover:underline">
                            {account.is_active ? '停用' : '啟用'}
                          </button>
                          <button onClick={() => handleDelete(account)} className="text-sm font-medium text-error hover:underline">
                            刪除
                          </button>
                        </div>
                      ) : (
                        <span className="text-sm text-fg-subtle">-</span>
                      )}
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
