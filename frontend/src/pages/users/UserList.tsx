import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { createUser, deleteUser, listUsers, resetUserPassword, setUserActive, setUserRole, updateUser } from '../../api/users'
import { useAuth } from '../../hooks/useAuth'
import { ActiveStatusBadge } from '../../components/ActiveStatusBadge'
import type { User, UserPayload, UserRole, UserUpdatePayload } from '../../types/user'

const ROLE_OPTIONS: { value: UserRole; label: string }[] = [
  { value: 'admin', label: '管理員' },
  { value: 'manager', label: '經理' },
  { value: 'sales', label: '業務' },
]

function roleLabel(role: UserRole): string {
  return ROLE_OPTIONS.find((option) => option.value === role)?.label ?? role
}

interface CreateFormState {
  name: string
  email: string
  password: string
  role: UserRole
  phone: string
  job_title: string
  hire_date: string
  notes: string
}

interface EditFormState {
  name: string
  email: string
  phone: string
  job_title: string
  hire_date: string
  notes: string
}

const emptyCreateForm: CreateFormState = {
  name: '',
  email: '',
  password: '',
  role: 'sales',
  phone: '',
  job_title: '',
  hire_date: '',
  notes: '',
}
const emptyEditForm: EditFormState = { name: '', email: '', phone: '', job_title: '', hire_date: '', notes: '' }

export function UserList() {
  const { user: currentUser } = useAuth()
  const isAdmin = currentUser?.role === 'admin'

  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [creating, setCreating] = useState(false)
  const [createForm, setCreateForm] = useState<CreateFormState>(emptyCreateForm)
  const [createError, setCreateError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState<EditFormState>(emptyEditForm)
  const [editError, setEditError] = useState<string | null>(null)

  const [resettingId, setResettingId] = useState<number | null>(null)
  const [resetPassword, setResetPassword] = useState('')
  const [resetError, setResetError] = useState<string | null>(null)

  function loadUsers() {
    setLoading(true)
    setError(null)
    listUsers()
      .then(setUsers)
      .catch(() => setError('使用者載入失敗'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    if (isAdmin) {
      loadUsers()
    } else {
      setLoading(false)
    }
  }, [isAdmin])

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
      setCreateError('請輸入姓名')
      return
    }
    if (!createForm.email.trim()) {
      setCreateError('請輸入電子郵件')
      return
    }
    if (createForm.password.length < 8) {
      setCreateError('密碼至少需要 8 個字元')
      return
    }

    const payload: UserPayload = {
      name: createForm.name.trim(),
      email: createForm.email.trim(),
      password: createForm.password,
      role: createForm.role,
      phone: createForm.phone.trim() || null,
      job_title: createForm.job_title.trim() || null,
      hire_date: createForm.hire_date || null,
      notes: createForm.notes.trim() || null,
    }

    setSubmitting(true)
    try {
      await createUser(payload)
      setCreating(false)
      setCreateForm(emptyCreateForm)
      loadUsers()
    } catch (err) {
      setCreateError(extractErrorMessage(err, '新增使用者失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  function startEdit(user: User) {
    setEditingId(user.id)
    setEditError(null)
    setEditForm({
      name: user.name,
      email: user.email,
      phone: user.phone ?? '',
      job_title: user.job_title ?? '',
      hire_date: user.hire_date ?? '',
      notes: user.notes ?? '',
    })
  }

  async function handleEditSubmit(event: FormEvent, id: number) {
    event.preventDefault()
    setEditError(null)

    if (!editForm.name.trim()) {
      setEditError('請輸入姓名')
      return
    }
    if (!editForm.email.trim()) {
      setEditError('請輸入電子郵件')
      return
    }

    const payload: UserUpdatePayload = {
      name: editForm.name.trim(),
      email: editForm.email.trim(),
      phone: editForm.phone.trim() || null,
      job_title: editForm.job_title.trim() || null,
      hire_date: editForm.hire_date || null,
      notes: editForm.notes.trim() || null,
    }

    setSubmitting(true)
    try {
      await updateUser(id, payload)
      setEditingId(null)
      loadUsers()
    } catch (err) {
      setEditError(extractErrorMessage(err, '更新使用者失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(user: User) {
    if (!window.confirm(`確定要刪除使用者「${user.name}」嗎？此操作無法復原。`)) {
      return
    }
    setError(null)
    try {
      await deleteUser(user.id)
      loadUsers()
    } catch (err) {
      setError(extractErrorMessage(err, '刪除使用者失敗'))
    }
  }

  async function toggleActive(user: User) {
    setError(null)
    try {
      await setUserActive(user.id, !user.is_active)
      loadUsers()
    } catch (err) {
      setError(extractErrorMessage(err, '更新使用者狀態失敗'))
    }
  }

  async function handleRoleChange(user: User, role: UserRole) {
    if (role === user.role) {
      return
    }
    setError(null)
    try {
      await setUserRole(user.id, role)
      loadUsers()
    } catch (err) {
      setError(extractErrorMessage(err, '更新角色失敗'))
    }
  }

  function startReset(user: User) {
    setResettingId(user.id)
    setResetPassword('')
    setResetError(null)
  }

  async function handleResetSubmit(event: FormEvent, id: number) {
    event.preventDefault()
    setResetError(null)

    if (resetPassword.length < 8) {
      setResetError('密碼至少需要 8 個字元')
      return
    }

    setSubmitting(true)
    try {
      await resetUserPassword(id, resetPassword)
      setResettingId(null)
      setResetPassword('')
    } catch (err) {
      setResetError(extractErrorMessage(err, '重設密碼失敗'))
    } finally {
      setSubmitting(false)
    }
  }

  if (!isAdmin) {
    return (
      <div className="flex flex-col gap-6">
        <div>
          <h1 className="text-xl font-semibold text-fg">員工/帳號管理</h1>
          <p className="mt-1 text-sm text-fg-muted">僅限管理員操作</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg">員工/帳號管理</h1>
          <p className="mt-1 text-sm text-fg-muted">建立與管理員工帳號、角色與基本資料</p>
        </div>
        <button
          onClick={() => {
            setCreating((v) => !v)
            setCreateError(null)
            setCreateForm(emptyCreateForm)
          }}
          className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover"
        >
          {creating ? '取消新增' : '新增員工'}
        </button>
      </div>

      {creating && (
        <form onSubmit={handleCreateSubmit} className="max-w-2xl rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">姓名 *</label>
              <input
                type="text"
                required
                value={createForm.name}
                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">電子郵件 *</label>
              <input
                type="email"
                required
                value={createForm.email}
                onChange={(e) => setCreateForm((f) => ({ ...f, email: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">密碼 *</label>
              <input
                type="password"
                required
                minLength={8}
                value={createForm.password}
                onChange={(e) => setCreateForm((f) => ({ ...f, password: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">角色 *</label>
              <select
                value={createForm.role}
                onChange={(e) => setCreateForm((f) => ({ ...f, role: e.target.value as UserRole }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              >
                {ROLE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">電話</label>
              <input
                type="text"
                value={createForm.phone}
                onChange={(e) => setCreateForm((f) => ({ ...f, phone: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">職稱</label>
              <input
                type="text"
                value={createForm.job_title}
                onChange={(e) => setCreateForm((f) => ({ ...f, job_title: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-fg-muted">到職日</label>
              <input
                type="date"
                value={createForm.hire_date}
                onChange={(e) => setCreateForm((f) => ({ ...f, hire_date: e.target.value }))}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
              <textarea
                value={createForm.notes}
                onChange={(e) => setCreateForm((f) => ({ ...f, notes: e.target.value }))}
                rows={2}
                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
              />
            </div>
          </div>

          {createError && <p className="mt-4 text-sm text-error">{createError}</p>}

          <div className="mt-6 flex gap-3">
            <button
              type="submit"
              disabled={submitting}
              className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
            >
              {submitting ? '建立中...' : '建立員工'}
            </button>
          </div>
        </form>
      )}

      {error && <p className="text-sm text-error">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-border bg-surface shadow-sm">
        <table className="min-w-full divide-y divide-border text-sm">
          <thead className="bg-surface-2">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">姓名</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">電子郵件</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">職稱</th>
              <th className="px-4 py-3 text-left font-medium text-fg-muted">角色</th>
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
            {!loading && users.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-fg-muted">
                  尚無使用者
                </td>
              </tr>
            )}
            {!loading &&
              users.map((user) => {
                const isSelf = currentUser?.id === user.id

                if (editingId === user.id) {
                  return (
                    <tr key={user.id} className="bg-surface-2">
                      <td colSpan={6} className="px-4 py-4">
                        <form onSubmit={(e) => handleEditSubmit(e, user.id)} className="flex flex-col gap-4">
                          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                              <label className="mb-1 block text-sm font-medium text-fg-muted">姓名 *</label>
                              <input
                                type="text"
                                required
                                value={editForm.name}
                                onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                            <div>
                              <label className="mb-1 block text-sm font-medium text-fg-muted">電子郵件 *</label>
                              <input
                                type="email"
                                required
                                value={editForm.email}
                                onChange={(e) => setEditForm((f) => ({ ...f, email: e.target.value }))}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                            <div>
                              <label className="mb-1 block text-sm font-medium text-fg-muted">電話</label>
                              <input
                                type="text"
                                value={editForm.phone}
                                onChange={(e) => setEditForm((f) => ({ ...f, phone: e.target.value }))}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                            <div>
                              <label className="mb-1 block text-sm font-medium text-fg-muted">職稱</label>
                              <input
                                type="text"
                                value={editForm.job_title}
                                onChange={(e) => setEditForm((f) => ({ ...f, job_title: e.target.value }))}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                            <div>
                              <label className="mb-1 block text-sm font-medium text-fg-muted">到職日</label>
                              <input
                                type="date"
                                value={editForm.hire_date}
                                onChange={(e) => setEditForm((f) => ({ ...f, hire_date: e.target.value }))}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                            <div className="sm:col-span-2">
                              <label className="mb-1 block text-sm font-medium text-fg-muted">備註</label>
                              <textarea
                                value={editForm.notes}
                                onChange={(e) => setEditForm((f) => ({ ...f, notes: e.target.value }))}
                                rows={2}
                                className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                              />
                            </div>
                          </div>
                          <p className="text-xs text-fg-muted">
                            角色請使用列表中的角色下拉選單；啟用／停用請使用「停用／啟用」按鈕；密碼請使用「重設密碼」。
                          </p>

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
                  )
                }

                if (resettingId === user.id) {
                  return (
                    <tr key={user.id} className="bg-surface-2">
                      <td colSpan={6} className="px-4 py-4">
                        <form onSubmit={(e) => handleResetSubmit(e, user.id)} className="flex flex-col gap-4">
                          <div className="max-w-sm">
                            <label className="mb-1 block text-sm font-medium text-fg-muted">{user.name} 的新密碼</label>
                            <input
                              type="password"
                              required
                              minLength={8}
                              value={resetPassword}
                              onChange={(e) => setResetPassword(e.target.value)}
                              className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                            />
                          </div>

                          {resetError && <p className="text-sm text-error">{resetError}</p>}

                          <div className="flex gap-3">
                            <button
                              type="submit"
                              disabled={submitting}
                              className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-50"
                            >
                              {submitting ? '儲存中...' : '重設密碼'}
                            </button>
                            <button
                              type="button"
                              onClick={() => setResettingId(null)}
                              className="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg-muted hover:bg-surface-2"
                            >
                              取消
                            </button>
                          </div>
                        </form>
                      </td>
                    </tr>
                  )
                }

                return (
                  <tr key={user.id} className="hover:bg-surface-2">
                    <td className="px-4 py-3 font-medium text-fg">{user.name}</td>
                    <td className="px-4 py-3">{user.email}</td>
                    <td className="px-4 py-3">{user.job_title || '-'}</td>
                    <td className="px-4 py-3">
                      <select
                        value={user.role}
                        disabled={isSelf}
                        onChange={(e) => handleRoleChange(user, e.target.value as UserRole)}
                        title={isSelf ? '無法變更自己的角色' : roleLabel(user.role)}
                        className="rounded-lg border border-border-strong px-2 py-1 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-60"
                      >
                        {ROLE_OPTIONS.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-4 py-3">
                      <ActiveStatusBadge active={user.is_active} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-3">
                        <button onClick={() => startEdit(user)} className="text-sm font-medium text-fg hover:underline">
                          編輯
                        </button>
                        <button onClick={() => startReset(user)} className="text-sm font-medium text-fg-muted hover:underline">
                          重設密碼
                        </button>
                        <button
                          onClick={() => toggleActive(user)}
                          disabled={isSelf && user.is_active}
                          className="text-sm font-medium text-fg-muted hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          {user.is_active ? '停用' : '啟用'}
                        </button>
                        <button
                          onClick={() => handleDelete(user)}
                          disabled={isSelf}
                          className="text-sm font-medium text-error hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          刪除
                        </button>
                      </div>
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
