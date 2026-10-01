import { afterEach, describe, expect, it, vi } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import { getRequestAuthGeneration } from '../auth/authSessionInvalidated'
import { AUTH_REQUEST_GENERATION_KEY } from '../auth/sessionState'

describe('apiClient auth request generation contract', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.resetModules()
  })

  it.each([401, 200, 500])('checks the session after 419 when /api/me returns %s', async (probeStatus) => {
    stubBrowserGlobals()
    vi.resetModules()
    const auth = await import('../auth/authSessionInvalidated')
    const { apiClient } = await import('./client')
    const invalidated = vi.fn()
    const unsubscribe = auth.onAuthSessionInvalidated(invalidated)
    const calls: string[] = []
    apiClient.defaults.adapter = async (config) => {
      calls.push(config.url!)
      if (config.url === '/api/me' && probeStatus === 200) return { data: { data: { id: 1 } }, status: 200, statusText: 'OK', headers: {}, config }
      throw { config, response: { status: config.url === '/api/me' ? probeStatus : 419, data: { message: 'CSRF token mismatch.' } } }
    }
    await expect(apiClient.post('/api/vehicles/1/final-payment', {})).rejects.toMatchObject({ response: { status: 419, data: { message: '工作階段已過期，請重新整理或登入。' } } })
    expect(calls).toEqual(['/api/vehicles/1/final-payment', '/api/me'])
    expect(invalidated).toHaveBeenCalledTimes(probeStatus === 401 ? 1 : 0)
    unsubscribe()
  })

  it('refreshes permissions after 403 and does not recurse when /api/me is forbidden', async () => {
    stubBrowserGlobals()
    vi.resetModules()
    const { apiClient } = await import('./client')
    const { onCurrentUserRefreshed } = await import('../auth/currentUserRefreshed')
    const refreshed = vi.fn()
    const unsubscribe = onCurrentUserRefreshed(refreshed)
    let meForbidden = false
    const calls: string[] = []
    apiClient.defaults.adapter = async (config) => {
      calls.push(config.url!)
      if (config.url === '/api/me' && !meForbidden) return { data: { data: { id: 1, role: 'sales' } }, status: 200, statusText: 'OK', headers: {}, config }
      throw { config, response: { status: 403, data: {} } }
    }
    await expect(apiClient.get('/api/users')).rejects.toMatchObject({ response: { status: 403 } })
    expect(refreshed).toHaveBeenCalledWith({ id: 1, role: 'sales' }, 'request-v2')
    meForbidden = true
    await expect(apiClient.get('/api/users')).rejects.toMatchObject({ response: { status: 403 } })
    expect(calls).toEqual(['/api/users', '/api/me', '/api/users', '/api/me'])
    unsubscribe()
  })

  it('does not probe or invalidate a new session for a stale 419', async () => {
    stubBrowserGlobals()
    vi.resetModules()
    const { apiClient } = await import('./client')
    const adapter = vi.fn(async (config: InternalAxiosRequestConfig) => {
      vi.mocked(localStorage.getItem).mockReturnValue('new-session')
      throw { config, response: { status: 419, data: {} } }
    })
    apiClient.defaults.adapter = adapter
    await expect(apiClient.post('/api/vehicles/1/final-payment')).rejects.toMatchObject({ response: { status: 419 } })
    expect(adapter).toHaveBeenCalledTimes(1)
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
    const passwordChangeGenerations: Array<string | null> = []
    const unsubscribeAuth =
      authSessionInvalidated.onAuthSessionInvalidated(
        (requestGeneration) => {
          receivedGenerations.push(requestGeneration)
        },
      )
    const unsubscribePassword =
      passwordChangeRequired.onPasswordChangeRequired((requestGeneration) => {
        passwordChangeGenerations.push(requestGeneration)
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
    expect(passwordChangeGenerations).toEqual([])
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
    const passwordChangeGenerations: Array<string | null> = []
    const unsubscribeAuth =
      authSessionInvalidated.onAuthSessionInvalidated(
        (requestGeneration) => {
          receivedGenerations.push(requestGeneration)
        },
      )
    const unsubscribePassword =
      passwordChangeRequired.onPasswordChangeRequired((requestGeneration) => {
        passwordChangeGenerations.push(requestGeneration)
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
    expect(passwordChangeGenerations).toEqual(['request-v2'])
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
