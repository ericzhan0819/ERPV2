import { describe, expect, it } from 'vitest'
import {
  AUTH_SESSION_VERSION_KEY,
  LOGOUT_STATE_KEY,
  decideExternalLoginStorageEvent,
  isExternalLoginStorageEvent,
  isCurrentAuthSessionRequest,
  markAuthSessionCompleted,
  readAuthSessionVersion,
  shouldInvalidateForExternalLogin,
} from './sessionState'

describe('cross-tab auth session state', () => {
  it('reads and compares the auth session version used by API requests', () => {
    const storage = {
      getItem: (key: string) =>
        key === AUTH_SESSION_VERSION_KEY ? 'session-v2' : null,
    }

    expect(readAuthSessionVersion(storage)).toBe('session-v2')
    expect(isCurrentAuthSessionRequest('session-v2', 'session-v2')).toBe(true)
    expect(isCurrentAuthSessionRequest('session-v1', 'session-v2')).toBe(false)
    expect(isCurrentAuthSessionRequest(null, 'session-v2')).toBe(false)
    expect(isCurrentAuthSessionRequest(null, null)).toBe(true)
  })

  it('uses the existing completed logout marker to propagate forced invalidation', () => {
    const writes: Array<[string, string]> = []
    markAuthSessionCompleted({
      setItem: (key: string, value: string) => {
        writes.push([key, value])
      },
    })

    expect(writes).toEqual([[LOGOUT_STATE_KEY, 'completed']])
  })

  it('recognizes a login version change and a cleared logout marker', () => {
    expect(
      isExternalLoginStorageEvent(AUTH_SESSION_VERSION_KEY, 'new-version'),
    ).toBe(true)
    expect(isExternalLoginStorageEvent(LOGOUT_STATE_KEY, null)).toBe(true)
    expect(isExternalLoginStorageEvent(LOGOUT_STATE_KEY, 'completed')).toBe(false)
    expect(isExternalLoginStorageEvent('unrelated', null)).toBe(false)
  })

  it('invalidates an idle tab that still has a current user', () => {
    expect(shouldInvalidateForExternalLogin(true, 'idle')).toBe(true)
  })

  it('does not alter a tab without an authenticated context', () => {
    expect(shouldInvalidateForExternalLogin(false, 'idle')).toBe(false)
  })

  it('preserves logout pending and blocked states', () => {
    expect(shouldInvalidateForExternalLogin(true, 'pending')).toBe(false)
    expect(shouldInvalidateForExternalLogin(true, 'blocked')).toBe(false)
  })

  it('invalidates an in-flight me request even before a user is loaded', () => {
    expect(
      decideExternalLoginStorageEvent(
        AUTH_SESSION_VERSION_KEY,
        'new-version',
        false,
        'idle',
      ),
    ).toEqual({
      handled: true,
      invalidateMeRequest: true,
      clearCurrentUser: false,
    })
  })

  it('clears only a rendered idle user and ignores unrelated storage events', () => {
    expect(
      decideExternalLoginStorageEvent(
        AUTH_SESSION_VERSION_KEY,
        'new-version',
        true,
        'idle',
      ),
    ).toEqual({
      handled: true,
      invalidateMeRequest: true,
      clearCurrentUser: true,
    })

    expect(
      decideExternalLoginStorageEvent('unrelated', null, true, 'idle'),
    ).toEqual({
      handled: false,
      invalidateMeRequest: false,
      clearCurrentUser: false,
    })
  })
})
