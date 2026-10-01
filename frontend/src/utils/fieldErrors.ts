import { isAxiosError } from 'axios'

export function extractFieldErrors(error: unknown): Record<string, string> {
  if (!isAxiosError(error) || error.response?.status !== 422) return {}
  const errors = error.response.data?.errors as Record<string, unknown> | undefined
  return Object.fromEntries(Object.entries(errors ?? {}).flatMap(([key, value]) =>
    Array.isArray(value) && typeof value[0] === 'string' ? [[key, value[0]]] : [],
  ))
}
