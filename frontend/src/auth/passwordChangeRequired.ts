import type { AxiosError } from 'axios'
import {
  getRequestAuthGeneration,
} from './authSessionInvalidated'
import type { AuthRequestGeneration } from './sessionState'

export const PASSWORD_CHANGE_REQUIRED_CODE = 'PASSWORD_CHANGE_REQUIRED'
export const PASSWORD_CHANGE_REQUIRED_PATH = '/change-password'

const PASSWORD_CHANGE_REQUIRED_EVENT = 'erpv2:password-change-required'
const passwordChangeRequiredEventTarget = new EventTarget()

class PasswordChangeRequiredEvent extends Event {
  readonly requestGeneration: AuthRequestGeneration

  constructor(requestGeneration: AuthRequestGeneration) {
    super(PASSWORD_CHANGE_REQUIRED_EVENT)
    this.requestGeneration = requestGeneration
  }
}

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
  notify: (
    requestGeneration: AuthRequestGeneration,
  ) => void = notifyPasswordChangeRequired,
): boolean {
  if (!isPasswordChangeRequiredError(error)) {
    return false
  }

  const requestGeneration = getRequestAuthGeneration(error)
  if (requestGeneration === undefined) {
    return false
  }

  notify(requestGeneration)
  return true
}

export function notifyPasswordChangeRequired(
  requestGeneration: AuthRequestGeneration,
): void {
  passwordChangeRequiredEventTarget.dispatchEvent(
    new PasswordChangeRequiredEvent(requestGeneration),
  )
}

export function onPasswordChangeRequired(
  listener: (requestGeneration: AuthRequestGeneration) => void,
): () => void {
  const eventListener = (event: Event) => {
    listener(
      (event as PasswordChangeRequiredEvent).requestGeneration,
    )
  }

  passwordChangeRequiredEventTarget.addEventListener(
    PASSWORD_CHANGE_REQUIRED_EVENT,
    eventListener,
  )
  return () =>
    passwordChangeRequiredEventTarget.removeEventListener(
      PASSWORD_CHANGE_REQUIRED_EVENT,
      eventListener,
    )
}
