import { useState } from 'react'
import { mutate } from '../lib/api'
import { Feedback, useAction, useResource } from './WorkspaceParts'

const labels = { openrouter: 'OpenRouter', gemini: 'Google Gemini', ollama: 'Local Ollama', huggingface: 'Hugging Face' }
export default function AiSettingsPage() {
  const resource = useResource('/admin/ai-settings')
  const [provider, setProvider] = useState('openrouter')
  const profile = resource.data?.profiles.find(item => item.provider === provider)
  return <><div className="page-heading"><span className="eyebrow">Administrator settings</span><h1>AI providers</h1><p>Configure separate providers, then choose one for each generation run. Keys are encrypted on the server and never returned to the browser.</p></div><Feedback resource={resource} />
    <div className="button-row provider-tabs">{Object.entries(labels).map(([id, label]) => <button key={id} className={`button ${provider === id ? 'primary' : 'secondary'}`} aria-pressed={provider === id} onClick={() => setProvider(id)}>{label}</button>)}</div>
    {profile ? <ProviderForm key={`${profile.id}-${profile.version}`} profile={profile} reload={resource.reload} /> : <p role="status">Loading provider settings…</p>}</>
}

function ProviderForm({ profile, reload }) {
  const [values, setValues] = useState({ ...profile, api_key: '', clear_key: false })
  const [models, setModels] = useState([])
  const [modelsLoaded, setModelsLoaded] = useState(false)
  const action = useAction()
  function change(event) { const { name, value, type, checked } = event.target; setValues(current => ({ ...current, [name]: type === 'checkbox' ? checked : value })) }
  async function save(event) {
    event.preventDefault()
    const payload = Object.fromEntries(['version', 'model', 'api_key', 'clear_key', 'enabled', 'endpoint', 'strict_json', 'free_only', 'limits'].map(key => [key, values[key]]))
    const result = await action.run(() => mutate('put', `/admin/ai-settings/${profile.provider}`, payload), 'Provider settings saved.')
    if (result) { setValues(current => ({ ...current, api_key: '' })); reload() }
  }
  async function loadModels() {
    const response = await action.run(() => mutate('post', `/admin/ai-settings/${profile.provider}/models`, { api_key: values.api_key || undefined }, { timeout: 20000 }), 'Available models loaded. Choose a model, then save your settings.')
    if (response) { setModels(response.data.models); setModelsLoaded(true) }
  }
  const limits = { user_daily_runs: ['Runs per user per day', 1, 1000], organization_daily_runs: ['Organization runs per day', 1, 10000], active_runs: ['Concurrent runs', 1, 5], duration_seconds: ['Run time limit (seconds)', 60, 1800], max_tokens: ['Run token allowance', 2000, 100000], max_output_tokens: ['Maximum output tokens per call', 512, 16000], context_characters: ['Source context characters', 2000, 60000], revision_passes: ['Quality revision passes', 0, 2], search_results: ['Public pages per run', 0, 3] }
  return <section className="editor-panel"><h2>{labels[profile.provider]} setup</h2><Feedback action={action} /><form className="form-stack" onSubmit={save}>
    <label className="checkbox-label"><input type="checkbox" name="enabled" checked={values.enabled} onChange={change} />Enable this provider</label>
    {profile.provider !== 'ollama' && <><label>API key<input type="password" name="api_key" value={values.api_key} autoComplete="new-password" maxLength="500" onChange={change} placeholder={profile.has_key ? 'A key is stored. Leave blank to keep it.' : 'Enter your provider API key'} required={values.enabled && !profile.has_key} /></label><label className="checkbox-label"><input type="checkbox" name="clear_key" checked={values.clear_key} onChange={change} />Remove the stored key</label></>}
    {profile.provider === 'ollama' && <label>Local Ollama address<input name="endpoint" value={values.endpoint} onChange={change} required /><small>Start Ollama locally and install a model first. No cloud provider is used for this profile.</small></label>}
    <label>Model ID<input name="model" list="available-ai-models" value={values.model} onChange={change} maxLength="200" placeholder={profile.provider === 'openrouter' || profile.provider === 'huggingface' ? 'Choose a currently free model route' : profile.provider === 'ollama' ? 'Your installed model name' : 'Load Gemini models below to choose'} /><datalist id="available-ai-models">{models.map(model => <option value={model.id} key={model.id}>{model.name}</option>)}</datalist><small>A key gives access to your provider account. Select a model separately; generation stays unavailable until a model is saved.</small></label>
    <button type="button" className="button secondary" disabled={action.busy} onClick={loadModels}>{profile.provider === 'openrouter' || profile.provider === 'huggingface' ? 'Load available free models' : profile.provider === 'ollama' ? 'Load installed models' : 'Load Gemini models'}</button>
    {models.length > 0 && <label>Available model<select value={models.some(model => model.id === values.model) ? values.model : ''} onChange={event => setValues(current => ({ ...current, model: event.target.value }))}><option value="">Choose a model</option>{models.map(model => <option key={model.id} value={model.id}>{model.name}</option>)}</select></label>}
    {profile.provider === 'openrouter' && <><label className="checkbox-label"><input type="checkbox" name="free_only" checked={values.free_only} onChange={change} />Use zero-price model routing only</label><label className="checkbox-label"><input type="checkbox" name="strict_json" checked={values.strict_json} onChange={change} />Strict JSON schema (only for models that support it)</label><p className="helper-text"><strong>Thinking is controlled automatically.</strong> Free routing prefers models that allow thinking to be switched off. Selected models that require thinking receive supported controls and room for an answer. An incomplete thinking response retries only that stage with more answer space and time, within the saved run limits.</p><p className="helper-text">Free availability and quotas can change. The app will show provider failures and will not switch to a paid model automatically. Standard mode still validates all structured outputs.</p></>}
    {profile.provider === 'huggingface' && <><label className="checkbox-label"><input type="checkbox" name="free_only" checked disabled />Free routes only (required)</label><p className="helper-text">Only live routes listed as free or with zero input and output prices are selectable. The route is rechecked before every stage and pinned to that provider; there is no paid fallback. Free routes can disappear. <a href="https://huggingface.co/settings/tokens" target="_blank" rel="noreferrer">Create a Hugging Face token</a> with permission to call Inference Providers. Hugging Face currently lists no monthly inference credits for free accounts, so testing depends on a route being marked free.</p></>}
    {profile.provider === 'huggingface' && modelsLoaded && models.length === 0 && <p role="status" className="helper-text">No verified free model routes are available right now. The app will not show or use paid routes.</p>}
    {profile.provider === 'gemini' && <p className="helper-text">Enter your Google AI Studio key, click Load Gemini models, choose an available model, and save. You can also save just the key first. Your Google account controls availability, free quotas, and billing.</p>}
    <details><summary>Run limits</summary><div className="form-columns generation-limits">{Object.entries(limits).map(([key, [label, min, max]]) => <label key={key}>{label}<input type="number" min={min} max={max} required value={values.limits[key]} onChange={event => setValues(current => ({ ...current, limits: { ...current.limits, [key]: Number(event.target.value) } }))} /></label>)}</div></details>
    <p className="helper-text">Cloud profiles send the selected brief and reference excerpts to that provider. Local Ollama sends them to the local server. There is no automatic fallback between providers.</p>
    <button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : 'Save provider settings'}</button>
  </form></section>
}
