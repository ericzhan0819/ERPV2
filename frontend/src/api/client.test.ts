import { afterEach, describe, expect, it, vi } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import { getRequestAuthGeneration } from '../auth/authSessionInvalidated'
import { AUTH_REQUEST_GENERATION_KEY } from '../auth/sessionState'

describe('apiClient auth request generation contract', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.resetModules()
  })

  it('stamps every request through the registered request interceptor', async () => {
    stubBrowserGlobals()
    vi.resetModules()

    const { apiClient } = await import('./client')
    let capturedConfig: InternalAxiosRequestConfig | undefined

    await apiClient.get('/request-generation-contract', {
      adapter: async (config) => {
        capturedConfig = config
        return {
          data: {},
          status: 200,
          statusText: 'OK',
          headers: {},
          config,
        }
      },
    })

    expect(capturedConfig).toBeDefined()
    expect(
      getRequestAuthGeneration({ config: capturedConfig }),
    ).toBe('request-v2')
  })

  it('routes a stamped 401 through the registered response interceptor', async () => {
    stubBrowserGlobals()
    vi.resetModules()

    const authSessionInvalidated = await import(
      '../auth/authSessionInvalidated'
    )
    const passwordChangeRequired = await import(
      '../auth/passwordChangeRequired'
    )
    const { apiClient } = await import('./client')
    const receivedGenerations: Array<string | null> = []
    let passwordChangeNotifications = 0
    const unsubscribeAuth =
      authSessionInvalidated.onAuthSessionInvalidated(
        (requestGeneration) => {
          receivedGenerations.push(requestGeneration)
        },
      )
    const unsubscribePassword =
      passwordChangeRequired.onPasswordChangeRequired(() => {
        passwordChangeNotifications += 1
      })

    await expect(
      apiClient.get('/unauthenticated-contract', {
        adapter: async (config) => {
          throw {
            config,
            response: {
              status: 401,
              data: { message: 'Unauthenticated' },
            },
          }
        },
      }),
    ).rejects.toMatchObject({
      response: { status: 401 },
    })

    expect(receivedGenerations).toEqual(['request-v2'])
    expect(passwordChangeNotifications).toBe(0)
    unsubscribeAuth()
    unsubscribePassword()
  })

  it('routes the dedicated password-required 409 through the response interceptor', async () => {
    stubBrowserGlobals()
    vi.resetModules()

    const authSessionInvalidated = await import(
      '../auth/authSessionInvalidated'
    )
    const passwordChangeRequired = await import(
      '../auth/passwordChangeRequired'
    )
    const { apiClient } = await import('./client')
    const receivedGenerations: Array<string | null> = []
    let passwordChangeNotifications = 0
    const unsubscribeAuth =
      authSessionInvalidated.onAuthSessionInvalidated(
        (requestGeneration) => {
          receivedGenerations.push(requestGeneration)
        },
      )
    const unsubscribePassword =
      passwordChangeRequired.onPasswordChangeRequired(() => {
        passwordChangeNotifications += 1
      })

    await expect(
      apiClient.get('/password-required-contract', {
        adapter: async (config) => {
          throw {
            config,
            response: {
              status: 409,
              data: {
                code: passwordChangeRequired.PASSWORD_CHANGE_REQUIRED_CODE,
              },
            },
          }
        },
      }),
    ).rejects.toMatchObject({
      response: {
        status: 409,
        data: {
          code: passwordChangeRequired.PASSWORD_CHANGE_REQUIRED_CODE,
        },
      },
    })

    expect(receivedGenerations).toEqual([])
    expect(passwordChangeNotifications).toBe(1)
    unsubscribeAuth()
    unsubscribePassword()
  })
})

function stubBrowserGlobals() {
  vi.stubGlobal('window', {
    location: {
      protocol: 'http:',
      hostname: 'localhost',
    },
  })
  vi.stubGlobal('localStorage', {
    getItem: vi.fn((key: string) =>
      key === AUTH_REQUEST_GENERATION_KEY ? 'request-v2' : null,
    ),
  })
}
