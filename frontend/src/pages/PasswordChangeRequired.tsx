import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { ThemeToggle } from '../components/ThemeToggle'
import { useAuth } from '../hooks/useAuth'
import {
  PASSWORD_UPDATED_RELOGIN_NOTICE,
  apiErrorMessage,
  apiFieldErrors,
  isCommittedCurrentUserUpdateWithStaleContext,
} from './authFormState'

type PasswordField = 'current_password' | 'password' | 'password_confirmation'
type PasswordFieldErrors = Partial<Record<PasswordField, string>>

const passwordFields = [
  'current_password',
  'password',
  'password_confirmation',
] as const

export function PasswordChangeRequired() {
  const { updatePassword, logout } = useAuth()
  const navigate = useNavigate()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [fieldErrors, setFieldErrors] = useState<PasswordFieldErrors>({})
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})

    if (password !== passwordConfirmation) {
      setFieldErrors({
        password_confirmation: '新密碼與確認密碼不一致',
      })
      return
    }

    setSubmitting(true)
    try {
      await updatePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      navigate('/dashboard', { replace: true })
    } catch (err) {
      if (isCommittedCurrentUserUpdateWithStaleContext(err)) {
        navigate('/login', {
          replace: true,
          state: { notice: PASSWORD_UPDATED_RELOGIN_NOTICE },
        })
        return
      }

      if (isAxiosError(err) && err.response?.status === 401) {
        navigate('/login', { replace: true })
        return
      }

      const nextFieldErrors = apiFieldErrors(err, passwordFields)
      setFieldErrors(nextFieldErrors)
      if (Object.keys(nextFieldErrors).length === 0) {
        setError(apiErrorMessage(err, '密碼修改失敗，請稍後再試'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  function handleLogout() {
    logout()
      .then(() => navigate('/login', { replace: true }))
      .catch(() => {
        // ProtectedRoute 會依 blocked 狀態顯示可重試的安全畫面。
      })
  }

  return (
    <div className="app-shell flex min-w-0 bg-bg">
      <main className="app-main flex w-full items-center justify-center pt-[calc(1rem+env(safe-area-inset-top,0px))]">
        <section className="w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8">
          <div className="flex justify-end">
            <ThemeToggle />
          </div>
          <h1 className="mt-4 text-2xl font-semibold tracking-tight text-fg">
            請先修改密碼
          </h1>
          <p className="mt-3 text-sm leading-6 text-fg-muted">
            此帳號目前使用管理員建立或重設的預設密碼。完成修改後，才能進入營運功能。
          </p>

          <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4">
            <PasswordInput
              id="current-password"
              label="目前密碼"
              autoComplete="current-password"
              value={currentPassword}
              error={fieldErrors.current_password}
              disabled={submitting}
              onChange={setCurrentPassword}
            />
            <PasswordInput
              id="new-password"
              label="新密碼"
              autoComplete="new-password"
              value={password}
              error={fieldErrors.password}
              disabled={submitting}
              minLength={8}
              onChange={setPassword}
            />
            <PasswordInput
              id="password-confirmation"
              label="確認新密碼"
              autoComplete="new-password"
              value={passwordConfirmation}
              error={fieldErrors.password_confirmation}
              disabled={submitting}
              minLength={8}
              onChange={setPasswordConfirmation}
            />

            <p role="alert" aria-live="polite" className="text-sm text-error empty:hidden">
              {error}
            </p>

            <button
              type="submit"
              disabled={submitting}
              className="mt-2 min-h-11 w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting ? '修改中...' : '修改密碼並繼續'}
            </button>
            <button
              type="button"
              onClick={handleLogout}
              disabled={submitting}
              className="min-h-11 w-full rounded-lg border border-border-strong px-4 py-2 text-sm font-medium text-fg hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50"
            >
              登出
            </button>
          </form>
        </section>
      </main>
    </div>
  )
}

function PasswordInput({
  id,
  label,
  autoComplete,
  value,
  error,
  disabled,
  minLength,
  onChange,
}: {
  id: string
  label: string
  autoComplete: 'current-password' | 'new-password'
  value: string
  error?: string
  disabled: boolean
  minLength?: number
  onChange: (value: string) => void
}) {
  const errorId = `${id}-error`

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-fg-muted">
        {label} <span aria-hidden="true" className="text-error">*</span>
      </label>
      <input
        id={id}
        name={id}
        type="password"
        autoComplete={autoComplete}
        required
        minLength={minLength}
        value={value}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        onChange={(event) => onChange(event.target.value)}
        className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
      />
      {error && (
        <p id={errorId} className="mt-1 text-sm text-error">
          {error}
        </p>
      )}
    </div>
  )
}
