import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import axios from 'axios'
import { api, mutate, errorMessage } from '../lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [session, setSession] = useState({ loading: true, user: null, setup_available: false, local_mail: false, error: '' })
  const requestVersion = useRef(0)

  const refresh = useCallback(async (signal) => {
    const version = ++requestVersion.current
    try {
      const { data } = await api.get('/auth/session', { signal })
      if (version === requestVersion.current) setSession({ ...data, loading: false, error: '' })
    } catch (error) {
      if (!axios.isCancel(error) && version === requestVersion.current) setSession(value => ({ ...value, loading: false, error: errorMessage(error) }))
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    refresh(controller.signal)
    const expired = () => {
      requestVersion.current += 1
      setSession(value => ({ ...value, user: null, loading: false }))
    }
    const focused = () => refresh()
    window.addEventListener('studio:session-expired', expired)
    window.addEventListener('focus', focused)
    return () => {
      controller.abort()
      window.removeEventListener('studio:session-expired', expired)
      window.removeEventListener('focus', focused)
    }
  }, [refresh])

  function setUser(user) {
    requestVersion.current += 1
    setSession(value => ({ ...value, user, setup_available: false, loading: false, error: '' }))
  }

  async function logout() {
    await mutate('post', '/auth/logout')
    setUser(null)
    await refresh()
  }

  return <AuthContext.Provider value={{ ...session, refresh, setUser, logout }}>{children}</AuthContext.Provider>
}

export function useAuth() {
  return useContext(AuthContext)
}
