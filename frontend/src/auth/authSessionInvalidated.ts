import type { AxiosError, InternalAxiosRequestConfig } from 'axios'
import type { AuthRequestGeneration } from './sessionState'

const AUTH_SESSION_INVALIDATED_EVENT = 'erpv2:auth-session-invalidated'
const authSessionInvalidatedEventTarget = new EventTarget()
const AUTH_REQUEST_GENERATION_CONFIG_KEY = '__erpv2AuthRequestGeneration'

type AuthTrackedRequestConfig = InternalAxiosRequestConfig & {
  [AUTH_REQUEST_GENERATION_CONFIG_KEY]?: AuthRequestGeneration
}

class AuthSessionInvalidatedEvent extends Event {
  readonly requestGeneration: AuthRequestGeneration

  constructor(requestGeneration: AuthRequestGeneration) {
    super(AUTH_SESSION_INVALIDATED_EVENT)
    this.requestGeneration = requestGeneration
  }
}

export function trackAuthSessionRequest<T extends InternalAxiosRequestConfig>(
  config: T,
  requestGeneration: AuthRequestGeneration,
): T {
  const trackedConfig = config as AuthTrackedRequestConfig
  trackedConfig[AUTH_REQUEST_GENERATION_CONFIG_KEY] = requestGeneration
  return config
}

export function getRequestAuthGeneration(
  error: unknown,
): AuthRequestGeneration | undefined {
  const config = (error as AxiosError)?.config as
    | AuthTrackedRequestConfig
    | undefined
  return config?.[AUTH_REQUEST_GENERATION_CONFIG_KEY]
}

export function isAuthSessionInvalidatedError(error: unknown): boolean {
  return (error as AxiosError)?.response?.status === 401
}

export function handleAuthSessionInvalidatedError(
  error: unknown,
  notify: (
    requestGeneration: AuthRequestGeneration,
  ) => void = notifyAuthSessionInvalidated,
): boolean {
  if (!isAuthSessionInvalidatedError(error)) {
    return false
  }

  const requestGeneration = getRequestAuthGeneration(error)
  if (requestGeneration === undefined) {
    return false
  }

  notify(requestGeneration)
  return true
}

export function notifyAuthSessionInvalidated(
  requestGeneration: AuthRequestGeneration,
): void {
  authSessionInvalidatedEventTarget.dispatchEvent(
    new AuthSessionInvalidatedEvent(requestGeneration),
  )
}

export function onAuthSessionInvalidated(
  listener: (requestGeneration: AuthRequestGeneration) => void,
): () => void {
  const eventListener = (event: Event) => {
    listener(
      (event as AuthSessionInvalidatedEvent).requestGeneration,
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
