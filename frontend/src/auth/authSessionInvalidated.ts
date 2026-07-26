import type { AxiosError } from 'axios'

const AUTH_SESSION_INVALIDATED_EVENT = 'erpv2:auth-session-invalidated'
const authSessionInvalidatedEventTarget = new EventTarget()

export function isAuthSessionInvalidatedError(error: unknown): boolean {
  return (error as AxiosError)?.response?.status === 401
}

export function handleAuthSessionInvalidatedError(
  error: unknown,
  notify: () => void = notifyAuthSessionInvalidated,
): boolean {
  if (!isAuthSessionInvalidatedError(error)) {
    return false
  }

  notify()
  return true
}

export function notifyAuthSessionInvalidated(): void {
  authSessionInvalidatedEventTarget.dispatchEvent(
    new Event(AUTH_SESSION_INVALIDATED_EVENT),
  )
}

export function onAuthSessionInvalidated(listener: () => void): () => void {
  authSessionInvalidatedEventTarget.addEventListener(
    AUTH_SESSION_INVALIDATED_EVENT,
    listener,
  )
  return () =>
    authSessionInvalidatedEventTarget.removeEventListener(
      AUTH_SESSION_INVALIDATED_EVENT,
      listener,
    )
}
