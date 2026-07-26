import { describe, expect, it } from 'vitest'
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
    let notifications = 0
    const notify = () => {
      notifications += 1
    }

    expect(
      handlePasswordChangeRequiredError(
        {
          response: {
            status: 409,
            data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
          },
        },
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
    expect(notifications).toBe(1)
  })

  it('connects the default API error handler to subscribers and supports cleanup', () => {
    let notifications = 0
    const unsubscribe = onPasswordChangeRequired(() => {
      notifications += 1
    })
    const error = {
      response: {
        status: 409,
        data: { code: PASSWORD_CHANGE_REQUIRED_CODE },
      },
    }

    expect(handlePasswordChangeRequiredError(error)).toBe(true)
    expect(notifications).toBe(1)

    unsubscribe()
    handlePasswordChangeRequiredError(error)
    expect(notifications).toBe(1)
  })
})
