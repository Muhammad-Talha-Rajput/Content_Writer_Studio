import { useCallback, useEffect, useState } from 'react'
import axios from 'axios'
import { api, errorMessage } from '../lib/api'

export function useResource(url) {
  const [record, setRecord] = useState({ url: null, value: null })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(Boolean(url))
  const [attempt, setAttempt] = useState(0)
  useEffect(() => {
    if (!url) { setRecord({ url: null, value: null }); setLoading(false); return }
    const controller = new AbortController()
    setLoading(true)
    setError('')
    api.get(url, { signal: controller.signal }).then(response => { if (!controller.signal.aborted) setRecord({ url, value: response.data }) })
      .catch(failure => { if (!axios.isCancel(failure)) setError(errorMessage(failure)) })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [url, attempt])
  const reload = useCallback(() => setAttempt(value => value + 1), [])
  return { data: record.url === url ? record.value : null, error, loading, reload }
}

export function useAction() {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  async function run(work, success) {
    setBusy(true); setError(''); setMessage('')
    try { const response = await work(); setMessage(success); return response }
    catch (failure) { setError(errorMessage(failure)); return null }
    finally { setBusy(false) }
  }
  function clear() { setError(''); setMessage('') }
  return { busy, error, message, run, clear }
}

export function Feedback({ action, resource }) {
  return <>{(action?.error || resource?.error) && <p role="alert" className="error-message">{action?.error || resource?.error}</p>}{action?.message && <p role="status" className="success-message">{action.message}</p>}</>
}

export function Field({ label, name, values, setValues, area, help, ...props }) {
  const change = event => setValues(value => ({ ...value, [name]: event.target.value }))
  return <label>{label}{area ? <textarea name={name} value={values[name] ?? ''} onChange={change} rows="4" {...props} /> : <input name={name} value={values[name] ?? ''} onChange={change} {...props} />}{help && <small>{help}</small>}</label>
}

export function Choice({ label, name, values, setValues, options, ...props }) {
  return <label>{label}<select name={name} value={values[name] ?? ''} onChange={event => setValues(value => ({ ...value, [name]: event.target.value }))} {...props}>{Object.entries(options).map(([key, text]) => <option key={key} value={key}>{text}</option>)}</select></label>
}

export const lines = value => (value || '').split('\n').map(item => item.trim()).filter(Boolean)

export function ArchiveFilter({ checked, onChange }) {
  return <label className="checkbox-label archive-filter"><input type="checkbox" checked={checked} onChange={event => onChange(event.target.checked)} />Include archived work</label>
}
