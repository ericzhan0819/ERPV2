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
})
