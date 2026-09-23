import { describe, expect, it } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import {
  getRequestAuthGeneration,
  handleAuthSessionInvalidatedError,
  isAuthSessionInvalidatedError,
  onAuthSessionInvalidated,
  trackAuthSessionRequest,
} from './authSessionInvalidated'

describe('invalid authenticated session API error', () => {
  it('recognizes only HTTP 401', () => {
    expect(isAuthSessionInvalidatedError({ response: { status: 401 } })).toBe(true)
    expect(isAuthSessionInvalidatedError({ response: { status: 409 } })).toBe(false)
    expect(isAuthSessionInvalidatedError(new Error('network error'))).toBe(false)
  })

  it('notifies subscribers for 401 and supports cleanup', () => {
    const receivedGenerations: Array<string | null> = []
    const unsubscribe = onAuthSessionInvalidated((requestGeneration) => {
      receivedGenerations.push(requestGeneration)
    })
    const config = trackAuthSessionRequest(
      { headers: {} } as InternalAxiosRequestConfig,
      'request-v1',
    )
    const error = { response: { status: 401 }, config }

    expect(getRequestAuthGeneration(error)).toBe('request-v1')
    expect(handleAuthSessionInvalidatedError(error)).toBe(true)
    expect(receivedGenerations).toEqual(['request-v1'])

    unsubscribe()
    handleAuthSessionInvalidatedError(error)
    expect(receivedGenerations).toEqual(['request-v1'])
  })

  it('ignores an unstamped 401 instead of guessing which session sent it', () => {
    expect(
      handleAuthSessionInvalidatedError({ response: { status: 401 } }),
    ).toBe(false)
  })

  it('preserves a null pre-login generation for bootstrap request races', () => {
    const receivedGenerations: Array<string | null> = []
    const config = trackAuthSessionRequest(
      { headers: {} } as InternalAxiosRequestConfig,
      null,
    )

    expect(
      handleAuthSessionInvalidatedError(
        { response: { status: 401 }, config },
        (requestGeneration) => {
          receivedGenerations.push(requestGeneration)
        },
      ),
    ).toBe(true)
    expect(receivedGenerations).toEqual([null])
  })
})
