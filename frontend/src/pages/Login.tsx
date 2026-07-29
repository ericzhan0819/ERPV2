import { useState } from 'react'
import type { FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { appConfig } from '../config/app'
import { useAuth } from '../hooks/useAuth'
import { apiErrorMessage, loginSuccessPath } from './authFormState'

export function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const notice =
    typeof location.state === 'object' &&
    location.state !== null &&
    'notice' in location.state &&
    typeof location.state.notice === 'string'
      ? location.state.notice
      : null
  const [loginIdentifier, setLoginIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      const loggedInUser = await login(loginIdentifier, password)
      navigate(loginSuccessPath(loggedInUser), { replace: true })
    } catch (err) {
      setError(apiErrorMessage(err, '登入失敗，請稍後再試'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="app-shell flex items-center justify-center bg-bg pt-[calc(1rem+env(safe-area-inset-top,0px))] pr-[calc(1rem+env(safe-area-inset-right,0px))] pb-[calc(1rem+env(safe-area-inset-bottom,0px))] pl-[calc(1rem+env(safe-area-inset-left,0px))]">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm rounded-2xl border border-border bg-surface p-8 shadow-sm"
      >
        <h1 className="text-xl font-semibold text-fg">{appConfig.systemName}</h1>
        <p
          role="status"
          aria-live="polite"
          className="mt-4 rounded-lg border border-success/30 bg-success/10 px-3 py-2 text-sm text-success empty:hidden"
        >
          {notice}
        </p>
        <p role="alert" aria-live="polite" className="mt-4 text-sm text-error empty:hidden">
          {error}
        </p>

        <div className="mt-6 flex flex-col gap-4">
          <div>
            <label htmlFor="login" className="mb-1 block text-sm font-medium text-fg-muted">
              帳號名稱或 Email <span aria-hidden="true" className="text-error">*</span>
            </label>
            <input
              id="login"
              name="login"
              type="text"
              autoComplete="username"
              required
              value={loginIdentifier}
              onChange={(e) => setLoginIdentifier(e.target.value)}
              className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>
          <div>
            <label htmlFor="password" className="mb-1 block text-sm font-medium text-fg-muted">
              密碼 <span aria-hidden="true" className="text-error">*</span>
            </label>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="mt-2 min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? '登入中...' : '登入'}
          </button>
        </div>
      </form>
    </div>
  )
}
