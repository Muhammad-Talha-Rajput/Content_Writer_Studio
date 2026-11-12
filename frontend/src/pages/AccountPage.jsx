import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { mutate, errorMessage } from '../lib/api'

export default function AccountPage() {
  const auth = useAuth()
  const navigate = useNavigate()
  const [values, setValues] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const { data } = await mutate('post', '/auth/change-password', values)
      auth.setUser(null)
      navigate('/login', { state: { message: data.message }, replace: true })
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <><div className="page-heading"><span className="eyebrow">Your account</span><h1>{auth.user.name}</h1><p>{auth.user.email} · {auth.user.role.toLowerCase()}</p></div><section className="account-panel"><h2>Change password</h2><p className="helper-text">Changing your password signs you out on every device.</p>{error && <p role="alert" className="error-message">{error}</p>}<form className="form-stack" onSubmit={submit}>
    {[['current_password', 'Current password'], ['password', 'New password'], ['password_confirmation', 'Confirm new password']].map(([name, label]) => <label key={name}>{label}<input name={name} type="password" autoComplete={name === 'current_password' ? 'current-password' : 'new-password'} required minLength={name === 'current_password' ? undefined : 12} maxLength="72" value={values[name]} onChange={event => setValues(value => ({ ...value, [name]: event.target.value }))} /></label>)}
    <p className="helper-text">Use at least 12 characters for your new password.</p><button className="button primary" disabled={busy}>{busy ? 'Saving…' : 'Change password'}</button></form></section></>
}
