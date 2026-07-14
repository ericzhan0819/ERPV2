import { isAxiosError } from 'axios'

const currency = new Intl.NumberFormat('zh-TW', { style: 'currency', currency: 'TWD', maximumFractionDigits: 0 })
export const formatCurrency = (amount: number) => currency.format(amount)
export const formatPercent = (bps: number) => `${(bps / 100).toFixed(bps % 100 === 0 ? 0 : 2)}%`

export function apiError(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const errors = error.response?.data?.errors as Record<string, string[]> | undefined
    const first = errors ? Object.values(errors)[0]?.[0] : undefined
    return first ?? error.response?.data?.message ?? fallback
  }
  return fallback
}

export function apiValidationErrors(error: unknown): Record<string, string> {
  if (!isAxiosError(error)) return {}
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined
  return Object.fromEntries(Object.entries(errors ?? {}).map(([field, messages]) => [field, messages[0]]))
}
