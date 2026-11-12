import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { mutate } from '../lib/api'
import { generationStages, runLabel } from '../lib/generationProgress'
import { Feedback, useAction, useResource } from './WorkspaceParts'

function StageArtifact({ type, value }) {
  if (value.edits) return <div className="stage-artifact"><p>{value.edits.length} targeted corrections applied to the existing draft.</p><details><summary>View changes</summary>{value.edits.map((edit,index) => <div key={index}>{edit.find && <p><strong>Original:</strong> {edit.find}</p>}{edit.text && <p><strong>Correction:</strong> {edit.text}</p>}</div>)}</details></div>
  if (type === 'PLAN') return <div className="stage-artifact"><p>{value.summary}</p><ol>{value.outline?.map((item, index) => <li key={index}>{item}</li>)}</ol></div>
  if (type === 'RESEARCH') return <div className="stage-artifact"><p>{value.evidence_sufficient ? 'Evidence available for drafting.' : 'Additional reference information is needed.'}</p><ul>{value.notes?.map((item, index) => <li key={index}>{item.fact}</li>)}{value.missing_information?.map((item, index) => <li key={`missing-${index}`}>{item}</li>)}</ul>{value.retrieval_warnings?.map((item, index) => <p className="helper-text" key={index}>{item}</p>)}</div>
  if (type === 'WRITE') return <div className="stage-artifact"><strong>{value.title}</strong><p>{value.blocks?.length || 0} content sections drafted.</p></div>
  const findings = value.gate_findings || value.findings?.filter(item => item.is_violation !== false) || []
  return <div className="stage-artifact"><p>{findings.some(item => item.severity === 'HARD') ? 'Required corrections remain.' : 'No hard quality failures remain.'}</p><ul>{findings.map((item, index) => <li key={index}>{item.message}</li>)}</ul></div>
}

export default function GenerationPanel({ brief, dirty, editable, onBriefChanged }) {
  const { user } = useAuth()
  const resource = useResource(`/briefs/${brief.id}/generation`)
  const sourceResource = useResource(`/briefs/${brief.id}/sources`)
  const [providerChoice, setProvider] = useState(null)
  const [excluded, setExcluded] = useState([])
  const [selectedRun, setSelectedRun] = useState(null)
  const [selectedVersion, setSelectedVersion] = useState(null)
  const active = resource.data?.runs.find(run => ['QUEUED', 'RUNNING'].includes(run.status))
  const runId = selectedRun || active?.id || resource.data?.runs[0]?.id
  const runResource = useResource(runId ? `/runs/${runId}` : null)
  const versionId = selectedVersion || resource.data?.versions[0]?.id
  const versionResource = useResource(versionId ? `/versions/${versionId}` : null)
  const action = useAction()
  const retryKey = useRef(null)
  const continueKey = useRef(null)
  const revisionKey = useRef(null)
  const wasActive = useRef(false)
  const activeId = active?.id
  const reloadSources = sourceResource.reload
  const reloadVersions = versionResource.reload
  const reloadRuns = resource.reload
  const reloadRun = runResource.reload
  useEffect(() => {
    if (wasActive.current && !activeId) { onBriefChanged(); reloadSources(); reloadVersions() }
    wasActive.current = Boolean(activeId)
  }, [activeId, onBriefChanged, reloadSources, reloadVersions])
  useEffect(() => {
    if (!activeId) return
    const timer = setInterval(() => { reloadRuns(); reloadRun() }, 2500)
    return () => clearInterval(timer)
  }, [activeId, reloadRuns, reloadRun])
  const summaryStatus = resource.data?.runs.find(run => run.id === runId)?.status
  useEffect(() => { if (runId) reloadRun() }, [runId, summaryStatus, reloadRun])
  useEffect(() => {
    const timer = setInterval(reloadSources, 5000)
    return () => clearInterval(timer)
  }, [reloadSources])
  const readySources = (sourceResource.data?.sources || []).filter(source => source.parse_status === 'READY' && !source.is_archived)
  const sourceIds = readySources.filter(source => !excluded.includes(source.id)).map(source => source.id)
  const profiles = resource.data?.profiles || []
  const provider = providerChoice || profiles.find(item => item.provider === resource.data?.default_provider && item.ready)?.provider || profiles.find(item => item.ready)?.provider || 'openrouter'
  const profile = profiles.find(item => item.provider === provider)
  const dailyLimitReached = profile?.daily_run_limit != null && profile.daily_runs_used >= profile.daily_run_limit
  const run = runResource.data?.run
  const failedCandidate = run?.error_code === 'QUALITY_FAILED' ? (run.steps || []).filter(step => step.step_type === 'QUALITY' && step.artifacts?.candidate).sort((a,b) => b.pass_no - a.pass_no)[0]?.artifacts.candidate : null
  const output = versionResource.data?.version
  async function start() {
    const payload = { provider, brief_version: brief.version, latest_version_id: resource.data?.versions[0]?.id || null, source_ids: sourceIds }
    const signature = JSON.stringify(payload)
    if (retryKey.current?.signature !== signature) retryKey.current = { signature, key: crypto.randomUUID() }
    const result = await action.run(() => mutate('post', `/briefs/${brief.id}/runs`, { ...payload, idempotency_key: retryKey.current.key }), 'Generation request accepted. Run details are shown below.')
    if (result) { setSelectedRun(result.data.run.id); retryKey.current = null; resource.reload(); onBriefChanged() }
  }
  async function cancel() {
    const result = await action.run(() => mutate('post', `/runs/${active.id}/cancel`), 'Run cancelled. Earlier saved versions are preserved; an in-flight provider call may still finish.')
    if (result) { resource.reload(); runResource.reload(); onBriefChanged() }
  }
  async function continueFromHere() {
    if (continueKey.current?.runId !== run?.id) continueKey.current = { runId: run.id, key: crypto.randomUUID() }
    const result = await action.run(() => mutate('post', `/runs/${run.id}/continue`, { idempotency_key: continueKey.current.key }), 'Continuing from completed stages. Earlier provider calls will be reused where possible.')
    if (result) { setSelectedRun(result.data.run.id); continueKey.current = null; resource.reload(); onBriefChanged() }
  }
  async function correctSavedDraft() {
    if (revisionKey.current?.runId !== output.source_run_id) revisionKey.current = { runId: output.source_run_id, key: crypto.randomUUID() }
    const result = await action.run(() => mutate('post', `/runs/${output.source_run_id}/revise`, { idempotency_key: revisionKey.current.key }), 'Correcting the saved draft with targeted edits, then checking quality again.')
    if (result) { setSelectedRun(result.data.run.id); revisionKey.current = null; resource.reload(); onBriefChanged() }
  }
  return <section className="editor-panel generation-panel" aria-label="AI generation"><div className="section-heading"><h2>AI draft generation</h2><button className="text-button" onClick={() => { resource.reload(); runResource.reload(); sourceResource.reload() }}>Refresh generation</button></div>
    <p className="helper-text">Plan → research → write → check. Drafts and findings are saved as versions for human review.</p><Feedback resource={resource} action={action} /><Feedback resource={sourceResource} />
    {editable && <><div className="form-columns"><label>AI provider<select name="generation_provider" aria-label="AI provider" value={provider} onChange={event => setProvider(event.target.value)} disabled={Boolean(active) || action.busy}>{profiles.map(item => <option key={item.provider} value={item.provider}>{item.provider === 'openrouter' ? 'OpenRouter' : item.provider === 'gemini' ? 'Google Gemini' : item.provider === 'huggingface' ? 'Hugging Face (free only)' : 'Local Ollama'}{item.ready ? '' : ' — setup needed'}</option>)}</select></label><div><span className="helper-text">Configured model</span><p>{profile?.model || 'No model selected'}</p></div></div>
    {!profile?.ready && <p className="archive-notice">This provider needs configuration.{user.role === 'ADMIN' ? <> <Link className="text-link" to="/ai-settings">Open AI provider settings</Link>.</> : ' Ask an administrator to configure it.'}</p>}
    <fieldset className="plain-fieldset generation-source-options" disabled={Boolean(active) || action.busy}><legend>References for this run</legend>{readySources.map(source => <label className="checkbox-label" key={source.id}><input type="checkbox" checked={!excluded.includes(source.id)} onChange={event => setExcluded(current => event.target.checked ? current.filter(id => id !== source.id) : [...current, source.id])} />{source.title}</label>)}{!readySources.length && <p className="helper-text">No ready references yet. Upload or retrieve a source in the library below.</p>}</fieldset>
    {brief.instructions.research_mode === 'PUBLIC_WEB' && <p className="helper-text">Public-web mode can retrieve up to three model-suggested pages. Links become evidence only after successful retrieval. No paid search plugin is used.</p>}
    {dirty && <p className="archive-notice">Save your brief changes before starting generation.</p>}
    {profile?.daily_run_limit != null && <p className={dailyLimitReached ? 'archive-notice' : 'helper-text'}>Your app runs today: {profile.daily_runs_used} / {profile.daily_run_limit}.{dailyLimitReached && <> The daily app allowance is reached. {user.role === 'ADMIN' ? <Link className="text-link" to="/ai-settings">Adjust Run limits in AI providers</Link> : 'Ask an administrator to adjust the allowance.'} Provider limits are separate.</>}</p>}
    <div className="button-row"><button className="button primary" disabled={action.busy || Boolean(active) || dirty || dailyLimitReached || !profile?.ready || (brief.instructions.research_mode === 'PROVIDED_ONLY' && !sourceIds.length)} onClick={start}>{active ? 'Generation in progress…' : 'Generate draft'}</button>{active && <button className="button secondary" disabled={action.busy} onClick={cancel}>Cancel run</button>}</div></>}
    {active && <p className="helper-text" role="status">The run is {active.status.toLowerCase()}. Keep the generation worker running; this page updates automatically.</p>}
    {resource.data?.runs.length > 0 && <label className="generation-history">Run history<select value={runId || ''} onChange={event => setSelectedRun(event.target.value)}>{resource.data.runs.map(item => <option value={item.id} key={item.id}>{new Date(item.created_at).toLocaleString()} · {runLabel(item)}</option>)}</select></label>}
    <Feedback resource={runResource} />{run && <><div className="run-heading"><strong>{runLabel(run)}</strong><span className="helper-text">{run.input_snapshot.provider.name} · {run.input_snapshot.provider.model} · {run.provider_usage?.total_tokens || 0} tokens{run.provider_usage?.includes_estimates ? ' (includes estimates)' : ''}</span></div>{run.error_message && <p className="error-message" role="alert">{run.error_message}</p>}
    {editable && !dirty && run.status === 'FAILED' && !['MISSING_EVIDENCE','ACCESS_CHANGED'].includes(run.error_code) && (run.steps || []).some(step => ['SUCCEEDED','REUSED'].includes(step.status) && step.artifacts) && <div className="button-row"><button className="button primary" disabled={action.busy || Boolean(active)} onClick={continueFromHere}>Continue from completed steps</button></div>}
    {dirty && run.status === 'FAILED' && <p className="archive-notice">Save the brief before continuing this run so its instructions match the saved checkpoints.</p>}
    {run.error_code === 'MISSING_EVIDENCE' && <p className="archive-notice">Add references that cover the missing information listed above, select them, then start a new run. Writing and quality checks have not started. <a className="text-link" href={`#source-library-${brief.id}`}>Go to source library</a></p>}
    {run.error_code === 'QUALITY_FAILED' && <p className="archive-notice">The candidate did not pass required quality checks. Continue applies targeted corrections to this draft; completed research is reused. Earlier saved versions are preserved.</p>}
    {failedCandidate && <article className="generated-draft"><h3>Candidate — quality fixes needed</h3>{failedCandidate.blocks.map(block => <p key={block.id}>{block.text}</p>)}</article>}
    <div className="run-steps">{generationStages(run).map(stage => <details className="run-step" key={stage.type}><summary><strong>{stage.type.toLowerCase()}</strong> · {stage.label}{stage.latest?.pass_no ? ` · revision ${stage.latest.pass_no}` : ''}</summary>
      {stage.latest?.status === 'SKIPPED' && <p className="helper-text">This stage did not start because the run stopped earlier.</p>}
      {stage.latest?.status === 'REUSED' && <p className="helper-text">Reused from an earlier run. This completed stage did not call the provider again.</p>}
      {stage.latest?.error_message && <p className="error-message">{stage.latest.error_message}</p>}
      {stage.latest?.artifacts && <StageArtifact type={stage.type} value={stage.latest.artifacts} />}
      {stage.earlier.length > 0 && <details className="stage-artifact"><summary>Earlier attempts ({stage.earlier.length})</summary>{stage.earlier.map(step => <div key={step.id}><p>Pass {step.pass_no + 1}, attempt {step.attempt_no + 1}: {step.status.toLowerCase()}</p>{step.error_message && <p className="helper-text">{step.error_message}</p>}</div>)}</details>}
    </details>)}</div></>}
    {resource.data?.versions.length > 0 && <><div className="section-heading generation-version-heading"><h3>Saved draft versions</h3><select aria-label="Content version" value={versionId || ''} onChange={event => setSelectedVersion(event.target.value)}>{resource.data.versions.map(item => <option key={item.id} value={item.id}>Version {item.version_no} · {item.title}</option>)}</select></div><Feedback resource={versionResource} />{output && editable && !dirty && output.source_run_id && output.quality_findings.some(item => item.severity === 'HARD') && <div className="button-row"><button className="button secondary" disabled={action.busy || Boolean(active)} onClick={correctSavedDraft}>Correct this draft</button><span className="helper-text">Applies targeted edits and checks quality again.</span></div>}{output && <><article className="generated-draft"><h2>{output.title}</h2>{output.format_metadata.email_subject && <p><strong>Subject:</strong> {output.format_metadata.email_subject}</p>}{output.blocks.map(block => <div className="draft-block" id={`block-${block.id}`} key={block.id}>{block.kind === 'HEADING' ? <h3>{block.text}</h3> : <p>{block.kind === 'BULLET' ? '• ' : ''}{block.text}</p>}{output.citations.filter(citation => citation.claim_anchor === block.id).map(citation => <details className="draft-citation" key={citation.id}><summary>{citation.source?.title || 'Source'} · {citation.locator}</summary><blockquote>{citation.supporting_excerpt}</blockquote></details>)}</div>)}</article><div className="quality-findings"><h3>Quality findings</h3><p className="helper-text">Checks help review; they do not guarantee factual accuracy or originality. This draft has not been approved for export.</p>{output.quality_findings.length ? output.quality_findings.map((finding, index) => <p key={index} className={finding.severity === 'HARD' ? 'error-message' : 'archive-notice'}><strong>{finding.origin === 'AI_JUDGMENT' ? 'AI review' : 'Rule check'} · {finding.severity.toLowerCase()}:</strong> {finding.message}</p>) : <p>No findings were reported. Human review is still required.</p>}</div></>}</>}
  </section>
}
