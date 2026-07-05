import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { createUser, deleteUser, listUsers, resetUserPassword, setUserActive, updateUser } from '../../api/users'
import { useAuth } from '../../hooks/useAuth'
import type { User, UserPayload, UserUpdatePayload } from '../../types/user'

interface CreateFormState {
  name: string
  email: string
  password: string
  is_admin: boolean
}

interface EditFormState {
  name: string
  email: string
  is_admin: boolean
}

const emptyCreateForm: CreateFormState = { name: '', email: '', password: '', is_admin: false }
const emptyEditForm: EditFormState = { name: '', email: '', is_admin: false }

export function UserList() {
  const { user: currentUser } = useAuth()
  const isAdmin = currentUser?.is_admin ?? false

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
      is_admin: createForm.is_admin,
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
    setEditForm({ name: user.name, email: user.email, is_admin: user.is_admin })
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
      is_admin: editForm.is_admin,
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
          <h1 className="text-xl font-semibold text-gray-900">使用者管理</h1>
          <p className="mt-1 text-sm text-gray-500">僅限管理員操作</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">使用者管理</h1>
          <p className="mt-1 text-sm text-gray-500">建立與管理系統使用者帳號</p>
        </div>
        <button
          onClick={() => {
            setCreating((v) => !v)
            setCreateError(null)
            setCreateForm(emptyCreateForm)
          }}
          className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
          {creating ? '取消新增' : '新增使用者'}
        </button>
      </div>

      {creating && (
        <form onSubmit={handleCreateSubmit} className="max-w-2xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">姓名</label>
              <input
                type="text"
                required
                value={createForm.name}
                onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">電子郵件</label>
              <input
                type="email"
                required
                value={createForm.email}
                onChange={(e) => setCreateForm((f) => ({ ...f, email: e.target.value }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">密碼</label>
              <input
                type="password"
                required
                minLength={8}
                value={createForm.password}
                onChange={(e) => setCreateForm((f) => ({ ...f, password: e.target.value }))}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
              />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={createForm.is_admin}
                  onChange={(e) => setCreateForm((f) => ({ ...f, is_admin: e.target.checked }))}
                />
                管理員
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
              {submitting ? '建立中...' : '建立使用者'}
            </button>
          </div>
        </form>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-gray-200 text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left font-medium text-gray-500">姓名</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">電子郵件</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">角色</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">狀態</th>
              <th className="px-4 py-3 text-left font-medium text-gray-500">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-gray-500">
                  載入中...
                </td>
              </tr>
            )}
            {!loading && users.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-gray-500">
                  尚無使用者
                </td>
              </tr>
            )}
            {!loading &&
              users.map((user) => {
                const isSelf = currentUser?.id === user.id

                if (editingId === user.id) {
                  return (
                    <tr key={user.id} className="bg-gray-50">
                      <td colSpan={5} className="px-4 py-4">
                        <form onSubmit={(e) => handleEditSubmit(e, user.id)} className="flex flex-col gap-4">
                          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                              <label className="mb-1 block text-sm font-medium text-gray-700">姓名</label>
                              <input
                                type="text"
                                required
                                value={editForm.name}
                                onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                              />
                            </div>
                            <div>
                              <label className="mb-1 block text-sm font-medium text-gray-700">電子郵件</label>
                              <input
                                type="email"
                                required
                                value={editForm.email}
                                onChange={(e) => setEditForm((f) => ({ ...f, email: e.target.value }))}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                              />
                            </div>
                            <div className="flex items-end">
                              <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input
                                  type="checkbox"
                                  checked={editForm.is_admin}
                                  onChange={(e) => setEditForm((f) => ({ ...f, is_admin: e.target.checked }))}
                                />
                                管理員
                              </label>
                            </div>
                          </div>
                          <p className="text-xs text-gray-500">啟用／停用請使用列表中的「停用／啟用」按鈕；密碼請使用「重設密碼」。</p>

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
                  )
                }

                if (resettingId === user.id) {
                  return (
                    <tr key={user.id} className="bg-gray-50">
                      <td colSpan={5} className="px-4 py-4">
                        <form onSubmit={(e) => handleResetSubmit(e, user.id)} className="flex flex-col gap-4">
                          <div className="max-w-sm">
                            <label className="mb-1 block text-sm font-medium text-gray-700">{user.name} 的新密碼</label>
                            <input
                              type="password"
                              required
                              minLength={8}
                              value={resetPassword}
                              onChange={(e) => setResetPassword(e.target.value)}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-gray-500 focus:outline-none"
                            />
                          </div>

                          {resetError && <p className="text-sm text-red-600">{resetError}</p>}

                          <div className="flex gap-3">
                            <button
                              type="submit"
                              disabled={submitting}
                              className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                            >
                              {submitting ? '儲存中...' : '重設密碼'}
                            </button>
                            <button
                              type="button"
                              onClick={() => setResettingId(null)}
                              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
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
                  <tr key={user.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{user.name}</td>
                    <td className="px-4 py-3">{user.email}</td>
                    <td className="px-4 py-3">{user.is_admin ? '管理員' : '一般使用者'}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`rounded-full px-2 py-1 text-xs font-medium ${
                          user.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600'
                        }`}
                      >
                        {user.is_active ? '啟用中' : '已停用'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-3">
                        <button onClick={() => startEdit(user)} className="text-sm font-medium text-gray-900 hover:underline">
                          編輯
                        </button>
                        <button onClick={() => startReset(user)} className="text-sm font-medium text-gray-600 hover:underline">
                          重設密碼
                        </button>
                        <button
                          onClick={() => toggleActive(user)}
                          disabled={isSelf && user.is_active}
                          className="text-sm font-medium text-gray-600 hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          {user.is_active ? '停用' : '啟用'}
                        </button>
                        <button
                          onClick={() => handleDelete(user)}
                          disabled={isSelf}
                          className="text-sm font-medium text-red-600 hover:underline disabled:cursor-not-allowed disabled:opacity-40"
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
