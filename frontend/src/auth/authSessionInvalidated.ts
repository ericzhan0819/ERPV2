import type { AxiosError, InternalAxiosRequestConfig } from 'axios'
import type { AuthSessionVersion } from './sessionState'

const AUTH_SESSION_INVALIDATED_EVENT = 'erpv2:auth-session-invalidated'
const authSessionInvalidatedEventTarget = new EventTarget()
const AUTH_SESSION_VERSION_CONFIG_KEY = '__erpv2AuthSessionVersion'

type AuthTrackedRequestConfig = InternalAxiosRequestConfig & {
  [AUTH_SESSION_VERSION_CONFIG_KEY]?: AuthSessionVersion
}

class AuthSessionInvalidatedEvent extends Event {
  readonly requestSessionVersion: AuthSessionVersion

  constructor(requestSessionVersion: AuthSessionVersion) {
    super(AUTH_SESSION_INVALIDATED_EVENT)
    this.requestSessionVersion = requestSessionVersion
  }
}

export function trackAuthSessionRequest<T extends InternalAxiosRequestConfig>(
  config: T,
  authSessionVersion: AuthSessionVersion,
): T {
  const trackedConfig = config as AuthTrackedRequestConfig
  trackedConfig[AUTH_SESSION_VERSION_CONFIG_KEY] = authSessionVersion
  return config
}

export function getRequestAuthSessionVersion(
  error: unknown,
): AuthSessionVersion | undefined {
  const config = (error as AxiosError)?.config as
    | AuthTrackedRequestConfig
    | undefined
  return config?.[AUTH_SESSION_VERSION_CONFIG_KEY]
}

export function isAuthSessionInvalidatedError(error: unknown): boolean {
  return (error as AxiosError)?.response?.status === 401
}

export function handleAuthSessionInvalidatedError(
  error: unknown,
  notify: (
    requestSessionVersion: AuthSessionVersion,
  ) => void = notifyAuthSessionInvalidated,
): boolean {
  if (!isAuthSessionInvalidatedError(error)) {
    return false
  }

  const requestSessionVersion = getRequestAuthSessionVersion(error)
  if (requestSessionVersion === undefined) {
    return false
  }

  notify(requestSessionVersion)
  return true
}

export function notifyAuthSessionInvalidated(
  requestSessionVersion: AuthSessionVersion,
): void {
  authSessionInvalidatedEventTarget.dispatchEvent(
    new AuthSessionInvalidatedEvent(requestSessionVersion),
  )
}

export function onAuthSessionInvalidated(
  listener: (requestSessionVersion: AuthSessionVersion) => void,
): () => void {
  const eventListener = (event: Event) => {
    listener(
      (event as AuthSessionInvalidatedEvent).requestSessionVersion,
    )
  }

  authSessionInvalidatedEventTarget.addEventListener(
    AUTH_SESSION_INVALIDATED_EVENT,
    eventListener,
  )
  return () =>
    authSessionInvalidatedEventTarget.removeEventListener(
      AUTH_SESSION_INVALIDATED_EVENT,
      eventListener,
    )
}
