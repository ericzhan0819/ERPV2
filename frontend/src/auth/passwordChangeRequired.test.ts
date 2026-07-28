import { describe, expect, it } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import { trackAuthSessionRequest } from './authSessionInvalidated'
import {
  PASSWORD_CHANGE_REQUIRED_CODE,
  handlePasswordChangeRequiredError,
  isPasswordChangeRequiredError,
  onPasswordChangeRequired,
} from './passwordChangeRequired'

describe('password change required API error', () => {
  it('recognizes only the dedicated 409 machine code', () => {
    expect(
      isPasswordChangeRequiredError({
        response: {
          status: 409,
          data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
        },
      }),
    ).toBe(true)

    expect(
      isPasswordChangeRequiredError({
        response: {
          status: 409,
          data: { code: 'OTHER_CONFLICT' },
        },
      }),
    ).toBe(false)

    expect(
      isPasswordChangeRequiredError({
        response: {
          status: 422,
          data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
        },
      }),
    ).toBe(false)
  })

  it('notifies the auth layer only for the dedicated machine code', () => {
    const notifications: Array<string | null> = []
    const notify = (requestGeneration: string | null) => {
      notifications.push(requestGeneration)
    }

    expect(
      handlePasswordChangeRequiredError(
        trackedPasswordRequiredError('request-v1'),
        notify,
      ),
    ).toBe(true)
    expect(
      handlePasswordChangeRequiredError(
        {
          response: {
            status: 409,
            data: { code: 'OTHER_CONFLICT' },
          },
        },
        notify,
      ),
    ).toBe(false)
    expect(notifications).toEqual(['request-v1'])
  })

  it('connects the default API error handler to subscribers and supports cleanup', () => {
    const notifications: Array<string | null> = []
    const unsubscribe = onPasswordChangeRequired((requestGeneration) => {
      notifications.push(requestGeneration)
    })
    const error = trackedPasswordRequiredError('request-v2')

    expect(handlePasswordChangeRequiredError(error)).toBe(true)
    expect(notifications).toEqual(['request-v2'])

    unsubscribe()
    handlePasswordChangeRequiredError(error)
    expect(notifications).toEqual(['request-v2'])
  })

  it('ignores an untracked password-required response instead of mutating a newer session', () => {
    const notifications: Array<string | null> = []

    expect(
      handlePasswordChangeRequiredError(
        {
          response: {
            status: 409,
            data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
          },
        },
        (requestGeneration) => notifications.push(requestGeneration),
      ),
    ).toBe(false)
    expect(notifications).toEqual([])
  })
})

function trackedPasswordRequiredError(requestGeneration: string | null) {
  const config = trackAuthSessionRequest(
    {} as InternalAxiosRequestConfig,
    requestGeneration,
  )

  return {
    config,
    response: {
      status: 409,
      data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
    },
  }
}
