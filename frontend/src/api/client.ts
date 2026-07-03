import axios from 'axios'

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

export async function ensureCsrfCookie() {
  await apiClient.get('/sanctum/csrf-cookie')
}
