import type { AxiosError } from 'axios'

export const PASSWORD_CHANGE_REQUIRED_CODE = 'PASSWORD_CHANGE_REQUIRED'
export const PASSWORD_CHANGE_REQUIRED_PATH = '/change-password'

const PASSWORD_CHANGE_REQUIRED_EVENT = 'erpv2:password-change-required'

interface ApiErrorPayload {
  code?: unknown
}

export function isPasswordChangeRequiredError(error: unknown): boolean {
  const axiosError = error as AxiosError<ApiErrorPayload>

  return (
    axiosError?.response?.status === 409 &&
    axiosError.response.data?.code === PASSWORD_CHANGE_REQUIRED_CODE
  )
}

export function handlePasswordChangeRequiredError(
  error: unknown,
  notify: () => void = notifyPasswordChangeRequired,
): boolean {
  if (!isPasswordChangeRequiredError(error)) {
    return false
  }

  notify()
  return true
}

export function notifyPasswordChangeRequired(): void {
  window.dispatchEvent(new Event(PASSWORD_CHANGE_REQUIRED_EVENT))
}

export function onPasswordChangeRequired(listener: () => void): () => void {
  window.addEventListener(PASSWORD_CHANGE_REQUIRED_EVENT, listener)
  return () => window.removeEventListener(PASSWORD_CHANGE_REQUIRED_EVENT, listener)
}
