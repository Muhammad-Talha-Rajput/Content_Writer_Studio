import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export async function mutate(method, url, data = {}, options = {}) {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  return api.request({ ...options, method, url, data })
}

export function errorMessage(error) {
  if (error.response?.status >= 500) return 'The service is temporarily unavailable. Please try again.'
  const fields = error.response?.data?.errors
  if (fields) return Object.values(fields).flat().join(' ')
  if (error.response?.status === 419) return 'Your session expired. Refresh this page and try again.'
  if (error.response?.status === 429) return error.response?.data?.message || 'Too many attempts. Wait a minute and try again.'
  return error.response?.data?.message || 'We could not reach the server. Please try again.'
}

api.interceptors.response.use(response => response, error => {
  if (error.response?.status === 401) window.dispatchEvent(new Event('studio:session-expired'))
  return Promise.reject(error)
})
