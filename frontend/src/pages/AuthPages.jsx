import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { mutate, errorMessage } from '../lib/api'

export function AuthFrame({ children }) {
  return <div className="auth-shell">
    <aside className="auth-intro"><Link className="brand" to="/"><span className="brand-mark">G</span><span>grands digital<small>CONTENT STUDIO</small></span></Link><div className="auth-story"><span className="eyebrow">Built for your team</span><h1>Your team’s writing workspace.</h1><p>Bring your clients, content briefs, and editorial decisions together. Every account has the access its work needs.</p><div className="auth-principles"><span>One brand voice</span><span>Clear responsibilities</span><span>Human approval</span></div></div><p className="auth-footer">Grands Digital · Content Studio</p></aside>
    <main className="auth-main" id="main-content"><div className="auth-card">{children}</div></main>
  </div>
}

const copy = {
  login: ['Welcome back', 'Sign in to your workspace.', 'Sign in'],
  welcome: ['Set up your workspace', 'Create the first administrator account for Grands Digital.', 'Create administrator account'],
  invite: ['Join your team', 'Set your name and password to accept your invitation.', 'Accept invitation'],
  forgot: ['Recover your account', 'Enter the email address associated with your account.', 'Request recovery'],
  reset: ['Choose a new password', 'Use a password you have not used for this account before.', 'Reset password'],
}

export default function AuthPage({ mode }) {
  const auth = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [query] = useSearchParams()
  const token = query.get('token') || ''
  const [values, setValues] = useState({ name: '', email: query.get('email') || '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState(location.state?.message || '')
  const [invitation, setInvitation] = useState(null)
  const [inspecting, setInspecting] = useState(mode === 'invite')
  const [title, description, action] = copy[mode]

  useEffect(() => {
    if (mode !== 'invite') return
    let active = true
    setInspecting(true)
    mutate('post', '/invitations/inspect', { token })
      .then(({ data }) => { if (active) setInvitation(data) })
      .catch(failure => { if (active) setError(errorMessage(failure)) })
      .finally(() => { if (active) setInspecting(false) })
    return () => { active = false }
  }, [mode, token])

  if (auth.user && !['invite', 'reset'].includes(mode)) return <Navigate to="/" replace />
  if (mode === 'welcome' && !auth.setup_available) return <Navigate to="/login" replace />

  function change(event) {
    setValues(value => ({ ...value, [event.target.name]: event.target.value }))
  }

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const endpoints = { login: '/auth/login', welcome: '/auth/bootstrap', invite: '/invitations/accept', forgot: '/auth/forgot-password', reset: '/auth/reset-password' }
      const { data } = await mutate('post', endpoints[mode], { ...values, token })
      if (data.user) {
        auth.setUser(data.user)
        navigate('/', { replace: true })
      } else {
        setMessage(data.message)
        setValues(value => ({ ...value, password: '', password_confirmation: '' }))
        if (mode === 'reset') await auth.refresh()
      }
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  const newPassword = ['welcome', 'invite', 'reset'].includes(mode)
  return <AuthFrame><span className="eyebrow">Grands Digital Content Studio</span><h2>{title}</h2><p className="auth-description">{description}</p>
    {message && <p className="success-message" role="status">{message}</p>}
    {error && <p className="error-message" role="alert">{error}</p>}
    {mode === 'invite' && inspecting ? <p role="status">Checking your invitation…</p> : mode === 'invite' && auth.user ? <div><p className="helper-text">You are signed in as {auth.user.email}. Sign out before accepting an invitation for a different account.</p><button className="button primary" onClick={() => auth.logout().catch(failure => setError(errorMessage(failure)))}>Sign out</button></div> : mode !== 'invite' || invitation ? <form onSubmit={submit} className="form-stack">
      {['welcome', 'invite'].includes(mode) && <label>Full name<input name="name" autoComplete="name" maxLength="200" required value={values.name} onChange={change} /></label>}
      {mode === 'invite' ? <div className="invitation-recipient"><strong>{invitation.email}</strong><span>{invitation.role.toLowerCase()} account · invitation expires {new Date(invitation.expires_at).toLocaleDateString()}</span></div> : <label>Email address<input name="email" type="email" autoComplete="username" maxLength="254" required value={values.email} onChange={change} /></label>}
      {mode !== 'forgot' && <label>{newPassword ? 'New password' : 'Password'}<input name="password" type="password" autoComplete={newPassword ? 'new-password' : 'current-password'} minLength={newPassword ? 12 : undefined} maxLength={newPassword ? 72 : 1024} required value={values.password} onChange={change} />{newPassword && <small>Use at least 12 characters.</small>}</label>}
      {newPassword && <label>Confirm password<input name="password_confirmation" type="password" autoComplete="new-password" minLength="12" maxLength="72" required value={values.password_confirmation} onChange={change} /></label>}
      {mode === 'forgot' && auth.local_mail && <p className="helper-text">Email delivery is not connected in this local workspace. Ask an administrator to create a recovery link for you. Recovery requests here are saved to the local mail log.</p>}
      <button className="button primary" type="submit" disabled={busy || (mode === 'reset' && !token)}>{busy ? 'Please wait…' : action}</button>
    </form> : null}
    {mode === 'reset' && !token && <p className="error-message" role="alert">Open a complete recovery link or request a new one.</p>}
    <div className="auth-links">{mode === 'login' ? <Link to="/forgot-password">Forgot your password?</Link> : <Link to="/login">Back to sign in</Link>}{auth.setup_available && mode === 'login' && <Link to="/welcome">Create the first administrator</Link>}</div>
    {mode === 'login' && <p className="auth-invite-note">New to the team? Ask your administrator for an invitation.</p>}
  </AuthFrame>
}
