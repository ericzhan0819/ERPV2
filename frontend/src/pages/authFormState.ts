import { isAxiosError } from 'axios'
import type { User } from '../types/user'
import { PASSWORD_CHANGE_REQUIRED_PATH } from '../auth/passwordChangeRequired'

interface ApiErrorPayload {
  message?: unknown
  errors?: Record<string, unknown>
}

export function loginSuccessPath(user: User): string {
  return user.must_change_password
    ? PASSWORD_CHANGE_REQUIRED_PATH
    : '/dashboard'
}

export function apiErrorMessage(
  error: unknown,
  fallback: string,
): string {
  if (
    isAxiosError<ApiErrorPayload>(error) &&
    typeof error.response?.data?.message === 'string'
  ) {
    return error.response.data.message
  }

  return fallback
}

export function apiFieldErrors<T extends string>(
  error: unknown,
  fields: readonly T[],
): Partial<Record<T, string>> {
  if (!isAxiosError<ApiErrorPayload>(error)) {
    return {}
  }

  const errors = error.response?.data?.errors
  if (!errors || typeof errors !== 'object') {
    return {}
  }

  return Object.fromEntries(
    fields.flatMap((field) => {
      const messages = errors[field]
      if (!Array.isArray(messages)) {
        return []
      }

      const message = messages.find(
        (value): value is string => typeof value === 'string',
      )
      return message ? [[field, message]] : []
    }),
  ) as Partial<Record<T, string>>
}
