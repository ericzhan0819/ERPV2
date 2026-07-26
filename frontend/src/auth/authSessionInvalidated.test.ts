import { describe, expect, it } from 'vitest'
import {
  handleAuthSessionInvalidatedError,
  isAuthSessionInvalidatedError,
  onAuthSessionInvalidated,
} from './authSessionInvalidated'

describe('invalid authenticated session API error', () => {
  it('recognizes only HTTP 401', () => {
    expect(isAuthSessionInvalidatedError({ response: { status: 401 } })).toBe(true)
    expect(isAuthSessionInvalidatedError({ response: { status: 409 } })).toBe(false)
    expect(isAuthSessionInvalidatedError(new Error('network error'))).toBe(false)
  })

  it('notifies subscribers for 401 and supports cleanup', () => {
    let notifications = 0
    const unsubscribe = onAuthSessionInvalidated(() => {
      notifications += 1
    })

    expect(handleAuthSessionInvalidatedError({ response: { status: 401 } })).toBe(true)
    expect(notifications).toBe(1)

    unsubscribe()
    handleAuthSessionInvalidatedError({ response: { status: 401 } })
    expect(notifications).toBe(1)
  })
})
