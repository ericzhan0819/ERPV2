import axios, { type AxiosError } from 'axios'
import type { User } from '../types/auth'
import { notifyCurrentUserRefreshed } from '../auth/currentUserRefreshed'
import {
  getRequestAuthGeneration,
  handleAuthSessionInvalidatedError,
  trackAuthSessionRequest,
} from '../auth/authSessionInvalidated'
import { handlePasswordChangeRequiredError } from '../auth/passwordChangeRequired'
import { readAuthRequestGeneration } from '../auth/sessionState'

const configuredBaseURL = import.meta.env.VITE_API_BASE_URL as string | undefined
const baseURL = configuredBaseURL || `${window.location.protocol}//${window.location.hostname}:8000`

export const apiClient = axios.create({
  baseURL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

apiClient.interceptors.request.use((config) =>
  trackAuthSessionRequest(config, readAuthRequestGeneration()),
)

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<{ message?: string }>) => {
    const status = error.response?.status
    const generation = getRequestAuthGeneration(error)
    if ((status === 419 || status === 403) && error.config?.url !== '/api/me'
      && generation !== undefined && generation === readAuthRequestGeneration()) {
      try {
        const { data } = await apiClient.get<{ data: User }>('/api/me')
        if (generation === readAuthRequestGeneration()) notifyCurrentUserRefreshed(data.data, generation)
      } catch {
        // /api/me 的 401 由既有 interceptor 使工作階段失效；不重送原寫入。
      }
    }
    if (status === 419 && error.response) {
      error.response.data = { ...error.response.data, message: '工作階段已過期，請重新整理或登入。' }
    }
    handleAuthSessionInvalidatedError(error)
    handlePasswordChangeRequiredError(error)
    return Promise.reject(error)
  },
)

export async function ensureCsrfCookie() {
  await apiClient.get('/sanctum/csrf-cookie')
}
