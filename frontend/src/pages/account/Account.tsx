import { useState } from 'react'
import type { FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../hooks/useAuth'
import { FormAlert } from '../../components/FormAlert'
import {
  PASSWORD_UPDATED_RELOGIN_NOTICE,
  PROFILE_UPDATED_RELOGIN_NOTICE,
  apiErrorMessage,
  apiFieldErrors,
  isCommittedCurrentUserUpdateWithStaleContext,
} from '../authFormState'
import {
  accountProfileForm,
  accountProfilePayload,
  accountRoleLabel,
  passwordConfirmationError,
} from './accountFormState'

type ProfileField = 'name' | 'username'
type PasswordField = 'current_password' | 'password' | 'password_confirmation'

const profileFields = ['name', 'username'] as const
const passwordFields = [
  'current_password',
  'password',
  'password_confirmation',
] as const

export function Account() {
  const { user } = useAuth()
  const navigate = useNavigate()

  if (!user) {
    return null
  }

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-6">
      <h1 className="text-2xl font-semibold tracking-tight text-fg">
        我的帳號
      </h1>

      <div className="grid min-w-0 gap-6 lg:grid-cols-2 lg:items-start">
        <ProfileSection
          key={`profile-${user.id}`}
          onCommittedStaleResponse={() => {
            navigate('/login', {
              replace: true,
              state: { notice: PROFILE_UPDATED_RELOGIN_NOTICE },
            })
          }}
        />
        <PasswordSection
          onSessionExpired={() => navigate('/login', { replace: true })}
          onCommittedStaleResponse={() => {
            navigate('/login', {
              replace: true,
              state: { notice: PASSWORD_UPDATED_RELOGIN_NOTICE },
            })
          }}
        />
      </div>
    </div>
  )
}

function ProfileSection({
  onCommittedStaleResponse,
}: {
  onCommittedStaleResponse: () => void
}) {
  const { user, updateProfile } = useAuth()
  const [form, setForm] = useState(() => accountProfileForm(user!))
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<ProfileField, string>>
  >({})
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!user) {
    return null
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (submitting) return

    setError(null)
    setSuccess(null)
    setFieldErrors({})

    const payload = accountProfilePayload(form)
    if (!payload.name) {
      setFieldErrors({ name: '請輸入顯示名稱' })
      return
    }

    setSubmitting(true)
    try {
      const updatedUser = await updateProfile(payload)
      setForm(accountProfileForm(updatedUser))
      setSuccess('個人資料已更新')
    } catch (caught) {
      if (isCommittedCurrentUserUpdateWithStaleContext(caught)) {
        onCommittedStaleResponse()
        return
      }

      const nextFieldErrors = apiFieldErrors(caught, profileFields)
      setFieldErrors(nextFieldErrors)
      if (Object.keys(nextFieldErrors).length === 0) {
        setError(apiErrorMessage(caught, '個人資料更新失敗，請稍後再試'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const usernameHelpId = 'account-username-help'
  const usernameErrorId = 'account-username-error'

  return (
    <section className="min-w-0 rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
      <h2 className="text-lg font-semibold text-fg">個人資料</h2>

      <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-4">
        <Feedback error={error} success={success} />
        <FormField
          id="account-name"
          name="name"
          label="顯示名稱"
          value={form.name}
          error={fieldErrors.name}
          disabled={submitting}
          maxLength={255}
          autoComplete="name"
          onChange={(name) => {
            setSuccess(null)
            setForm((current) => ({ ...current, name }))
          }}
        />

        <div>
          <label
            htmlFor="account-username"
            className="mb-1 block text-sm font-medium text-fg-muted"
          >
            帳號名稱
          </label>
          <input
            id="account-username"
            name="username"
            type="text"
            inputMode="text"
            autoCapitalize="none"
            autoCorrect="off"
            autoComplete="username"
            maxLength={30}
            value={form.username}
            disabled={submitting}
            aria-invalid={fieldErrors.username ? true : undefined}
            aria-describedby={[
              usernameHelpId,
              fieldErrors.username ? usernameErrorId : null,
            ].filter(Boolean).join(' ')}
            onChange={(event) => {
              setSuccess(null)
              setForm((current) => ({
                ...current,
                username: event.target.value,
              }))
            }}
            className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-base focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
          />
          <p id={usernameHelpId} className="mt-1 text-sm leading-5 text-fg-muted">
            {user.username === null
              ? '未設定時使用 Email 登入。'
              : '也可使用 Email 登入；清除帳號名稱後請改用 Email。'}
            {' '}3～30 字元，可用英數、._-；儲存時轉小寫。
          </p>
          {fieldErrors.username && (
            <p id={usernameErrorId} className="mt-1 text-sm text-error">
              {fieldErrors.username}
            </p>
          )}
        </div>

        <dl className="grid min-w-0 gap-4 rounded-xl border border-border bg-surface-2 p-4 sm:grid-cols-2">
          <ReadOnlyValue label="Email" value={user.email} />
          <ReadOnlyValue label="角色" value={accountRoleLabel(user.role)} />
        </dl>

        <button
          type="submit"
          disabled={submitting}
          className="min-h-11 w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:self-start"
        >
          {submitting ? '儲存中...' : '儲存個人資料'}
        </button>
      </form>
    </section>
  )
}

function PasswordSection({
  onSessionExpired,
  onCommittedStaleResponse,
}: {
  onSessionExpired: () => void
  onCommittedStaleResponse: () => void
}) {
  const { updatePassword } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<PasswordField, string>>
  >({})
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (submitting) return

    setError(null)
    setSuccess(null)
    setFieldErrors({})

    const confirmationError = passwordConfirmationError(
      password,
      passwordConfirmation,
    )
    if (confirmationError) {
      setFieldErrors({ password_confirmation: confirmationError })
      return
    }

    setSubmitting(true)
    try {
      await updatePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      setSuccess('密碼已更新')
    } catch (caught) {
      if (isCommittedCurrentUserUpdateWithStaleContext(caught)) {
        onCommittedStaleResponse()
        return
      }

      if (isAxiosError(caught) && caught.response?.status === 401) {
        onSessionExpired()
        return
      }

      const nextFieldErrors = apiFieldErrors(caught, passwordFields)
      setFieldErrors(nextFieldErrors)
      if (Object.keys(nextFieldErrors).length === 0) {
        setError(apiErrorMessage(caught, '密碼更新失敗，請稍後再試'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="min-w-0 rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
      <h2 className="text-lg font-semibold text-fg">修改密碼</h2>

      <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-4">
        <Feedback error={error} success={success} />
        <PasswordField
          id="account-current-password"
          name="current_password"
          label="目前密碼"
          autoComplete="current-password"
          value={currentPassword}
          error={fieldErrors.current_password}
          disabled={submitting}
          onChange={(value) => {
            setSuccess(null)
            setCurrentPassword(value)
          }}
        />
        <PasswordField
          id="account-new-password"
          name="password"
          label="新密碼"
          autoComplete="new-password"
          value={password}
          error={fieldErrors.password}
          disabled={submitting}
          minLength={8}
          help="至少 8 個字元，且不可與目前密碼相同。"
          onChange={(value) => {
            setSuccess(null)
            setPassword(value)
          }}
        />
        <PasswordField
          id="account-password-confirmation"
          name="password_confirmation"
          label="確認新密碼"
          autoComplete="new-password"
          value={passwordConfirmation}
          error={fieldErrors.password_confirmation}
          disabled={submitting}
          minLength={8}
          onChange={(value) => {
            setSuccess(null)
            setPasswordConfirmation(value)
          }}
        />

        <button
          type="submit"
          disabled={submitting}
          className="min-h-11 w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-fg hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-surface disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:self-start"
        >
          {submitting ? '更新中...' : '更新密碼'}
        </button>
      </form>
    </section>
  )
}

function FormField({
  id,
  name,
  label,
  value,
  error,
  disabled,
  maxLength,
  autoComplete,
  onChange,
}: {
  id: string
  name: string
  label: string
  value: string
  error?: string
  disabled: boolean
  maxLength?: number
  autoComplete?: string
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
        name={name}
        type="text"
        required
        maxLength={maxLength}
        autoComplete={autoComplete}
        value={value}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        onChange={(event) => onChange(event.target.value)}
        className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-base focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
      />
      {error && (
        <p id={errorId} className="mt-1 text-sm text-error">
          {error}
        </p>
      )}
    </div>
  )
}

function PasswordField({
  id,
  name,
  label,
  autoComplete,
  value,
  error,
  disabled,
  minLength,
  help,
  onChange,
}: {
  id: string
  name: string
  label: string
  autoComplete: 'current-password' | 'new-password'
  value: string
  error?: string
  disabled: boolean
  minLength?: number
  help?: string
  onChange: (value: string) => void
}) {
  const helpId = `${id}-help`
  const errorId = `${id}-error`

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-fg-muted">
        {label} <span aria-hidden="true" className="text-error">*</span>
      </label>
      <input
        id={id}
        name={name}
        type="password"
        autoComplete={autoComplete}
        required
        minLength={minLength}
        value={value}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        aria-describedby={[
          help ? helpId : null,
          error ? errorId : null,
        ].filter(Boolean).join(' ') || undefined}
        onChange={(event) => onChange(event.target.value)}
        className="form-control-touch w-full rounded-lg border border-border-strong px-3 py-2 text-base focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring"
      />
      {help && (
        <p id={helpId} className="mt-1 text-sm leading-5 text-fg-muted">
          {help}
        </p>
      )}
      {error && (
        <p id={errorId} className="mt-1 text-sm text-error">
          {error}
        </p>
      )}
    </div>
  )
}

function ReadOnlyValue({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium text-fg-muted">{label}</dt>
      <dd className="mt-1 break-words text-sm text-fg">{value}</dd>
    </div>
  )
}

function Feedback({
  error,
  success,
}: {
  error: string | null
  success: string | null
}) {
  return (
    <>
      <FormAlert message={error} focusOnShow />
      <p role="status" aria-live="polite" className="text-sm text-success empty:hidden">
        {success}
      </p>
    </>
  )
}
