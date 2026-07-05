export function generateIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const randomPart = Math.random().toString(36).slice(2)
  const timePart = Date.now().toString(36)

  return `fallback-${timePart}-${randomPart}`
}
