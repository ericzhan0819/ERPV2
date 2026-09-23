export const BUSINESS_TIME_ZONE = 'Asia/Taipei'

export function formatBusinessDate(value: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: BUSINESS_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(value)
  const byType = Object.fromEntries(parts.map((part) => [part.type, part.value]))

  return `${byType.year}-${byType.month}-${byType.day}`
}

export function formatBusinessDateTime(value: string | Date): string {
  return new Intl.DateTimeFormat('zh-TW', {
    timeZone: BUSINESS_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).format(typeof value === 'string' ? new Date(value) : value)
}
