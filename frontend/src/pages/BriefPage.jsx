import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { api, mutate } from '../lib/api'
import { Choice, Field, Feedback, lines, useAction, useResource } from './WorkspaceParts'
import SourcePanel from './SourcePanel'
import GenerationPanel from './GenerationPanel'

const defaults = { topic: '', content_type: 'BLOG', objective: 'INFORM', audience: '', language: 'English', tone: 'Professional and approachable', target_words: 1000, social_platform: 'LinkedIn', target_characters: 600, primary_keyword: '', secondary_keywords: '', call_to_action: '', talking_points: '', reference_urls: '', research_mode: 'PROVIDED_ONLY', additional_instructions: '', reviewer_id: '' }

export default function BriefPage() {
  const { id, projectId: presetProjectId } = useParams()
  const editing = Boolean(id)
  const { user } = useAuth()
  const navigate = useNavigate()
  const briefResource = useResource(editing ? `/briefs/${id}` : null)
  const projectsResource = useResource('/projects')
  const clientsResource = useResource('/clients')
  const optionsResource = useResource('/content-options')
  const [clientId, setClientId] = useState('')
  const [projectId, setProjectId] = useState(presetProjectId || '')
  const projectResource = useResource(!editing && projectId ? `/projects/${projectId}` : null)
  const brief = briefResource.data?.brief
  const project = editing ? brief?.project : projectResource.data?.project?.id === projectId ? projectResource.data.project : null
  const reviewersResource = useResource(project?.client_id ? `/clients/${project.client_id}/reviewers` : null)
  const action = useAction()
  const [values, setValues] = useState(defaults)
  const [refreshBrand, setRefreshBrand] = useState(false)
  const [dirty, setDirty] = useState(false)
  const options = optionsResource.data
  const workspaceEditable = user.role !== 'REVIEWER' && project && !project.is_archived && !project.client.is_archived && !brief?.is_archived
  const canEdit = workspaceEditable && brief?.status !== 'IN_PROGRESS'

  useEffect(() => {
    if (!brief) return
    setProjectId(brief.project_id); setClientId(brief.project.client_id)
    setValues({ ...defaults, ...brief.instructions, topic: brief.topic, content_type: brief.content_type,
      secondary_keywords: (brief.instructions.secondary_keywords || []).join('\n'), reference_urls: (brief.instructions.reference_urls || []).join('\n'), reviewer_id: brief.instructions.reviewer_id || '' })
    setRefreshBrand(false); setDirty(false)
  }, [brief])

  useEffect(() => {
    if (editing || !project) return
    setClientId(project.client_id)
    setValues({ ...defaults, audience: project.client.brand_profile?.default_audience || '', tone: project.client.brand_profile?.default_tone || defaults.tone })
    setDirty(false)
  }, [editing, project?.id])

  function changeValues(value) { setDirty(true); setValues(value) }

  async function applyBrand() {
    const result = await action.run(() => api.get(`/projects/${projectId}`), 'Current brand defaults applied. Save the brief to keep them.')
    if (result) {
      const profile = result.data.project.client.brand_profile
      setValues(value => ({ ...value, audience: profile?.default_audience || value.audience, tone: profile?.default_tone || value.tone }))
      setRefreshBrand(true); setDirty(true)
    }
  }

  async function submit(event) {
    event.preventDefault()
    const payload = { ...values, secondary_keywords: lines(values.secondary_keywords), reference_urls: lines(values.reference_urls),
      reviewer_id: values.reviewer_id || null, refresh_brand: refreshBrand, ...(editing ? { version: brief.version } : {}) }
    const result = await action.run(() => mutate(editing ? 'put' : 'post', editing ? `/briefs/${id}` : `/projects/${projectId}/briefs`, payload), 'Brief saved.')
    if (result) {
      setDirty(false)
      if (editing) briefResource.reload()
      else navigate(`/briefs/${result.data.brief.id}`, { replace: true })
    }
  }

  async function archive() {
    const result = await action.run(() => mutate('patch', `/briefs/${id}/archive`, { version: brief.version, is_archived: !brief.is_archived }), brief.is_archived ? 'Brief restored.' : 'Brief archived.')
    if (result) briefResource.reload()
  }

  if ((editing && !brief) || !options || !clientsResource.data || !projectsResource.data) {
    const resources = [briefResource, optionsResource, clientsResource, projectsResource]
    return <>{resources.map((resource, index) => <Feedback key={index} resource={resource} />)}{resources.some(resource => resource.loading) && <p role="status">Loading brief…</p>}{resources.some(resource => resource.error) && <button className="button secondary" onClick={() => resources.forEach(resource => resource.reload())}>Try again</button>}</>
  }
  const activeProjects = projectsResource.data?.projects.filter(item => !item.is_archived && !item.client.is_archived) || []
  const reviewerOptions = { '': 'Assign later', ...Object.fromEntries((reviewersResource.data?.reviewers || []).map(item => [item.id, `${item.name} (${item.role.toLowerCase()})`])) }
  if (values.reviewer_id && !reviewerOptions[values.reviewer_id]) reviewerOptions[values.reviewer_id] = 'Previously selected reviewer — choose another if required'

  return <><Link className="back-link" to={project ? `/projects/${project.id}` : '/briefs'}>← {project?.name || 'Content briefs'}</Link><div className="page-heading"><span className="eyebrow">{editing ? 'Saved content brief' : 'Content planning'}</span><div className="title-actions"><h1>{editing ? brief.topic : 'New content brief'}</h1>{editing && user.role !== 'REVIEWER' && !project?.is_archived && !project?.client.is_archived && <button type="button" className="button secondary" disabled={action.busy || dirty} onClick={archive}>{brief.is_archived ? 'Restore brief' : 'Archive brief'}</button>}</div><p>Define the audience, message, and format. Saving a brief does not start research or generation.</p></div>
    <Feedback action={action} resource={briefResource} />{[projectsResource, clientsResource, optionsResource, projectResource, reviewersResource].map((resource, index) => resource.error && <p key={index} role="alert" className="error-message">{resource.error}</p>)}
    {(brief?.is_archived || project?.is_archived || project?.client.is_archived) && <p className="archive-notice">Archived work is read-only. Restore the client, project, and brief to make changes.</p>}
    {user.role === 'REVIEWER' && <p className="helper-text">Your reviewer role has read-only access to this brief.</p>}
    <section className="editor-panel"><form className="form-stack brief-form" onSubmit={submit}>
      <div className="section-heading"><h2>Client and project</h2>{editing && <button type="button" className="text-button" onClick={() => { action.clear(); briefResource.reload() }}>Reload saved version</button>}</div>
      <div className="form-columns"><label>Client<select name="client_id" required disabled={editing || Boolean(presetProjectId) || user.role === 'REVIEWER'} value={clientId} onChange={event => { setClientId(event.target.value); setProjectId(''); setValues(defaults) }}><option value="">Choose a client</option>{clientsResource.data?.clients.filter(client => editing || !client.is_archived).map(client => <option value={client.id} key={client.id}>{client.name}</option>)}</select></label>
      <label>Project<select name="project_id" required disabled={editing || Boolean(presetProjectId) || user.role === 'REVIEWER'} value={projectId} onChange={event => setProjectId(event.target.value)}><option value="">Choose a project</option>{(editing && project ? [project] : activeProjects.filter(item => item.client_id === clientId)).map(item => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label></div>
      {!editing && clientId && activeProjects.filter(item => item.client_id === clientId).length === 0 && <p className="helper-text">This client needs an active project first. <Link className="text-link" to={`/clients/${clientId}`}>Open client workspace</Link>.</p>}
      {options && project && <><fieldset disabled={!canEdit || action.busy} className="plain-fieldset"><div className="section-heading"><h2>Writing brief</h2>{canEdit && <button className="text-button" type="button" onClick={applyBrand}>Apply current brand defaults</button>}</div>
      <Field label="Topic or working title" name="topic" values={values} setValues={changeValues} required minLength="5" maxLength="200" />
      <div className="form-columns"><Choice label="Content type" name="content_type" values={values} setValues={changeValues} options={options.types} required /><Choice label="Content objective" name="objective" values={values} setValues={changeValues} options={options.objectives} required /></div>
      <Field label="Target audience" name="audience" area values={values} setValues={changeValues} required minLength="10" maxLength="500" />
      <div className="form-columns"><Field label="Tone of voice" name="tone" values={values} setValues={changeValues} required maxLength="300" /><Choice label="Language" name="language" values={values} setValues={changeValues} options={Object.fromEntries(options.languages.map(item => [item, item]))} required /></div>
      {values.content_type === 'SOCIAL' ? <div className="form-columns"><Choice label="Social platform" name="social_platform" values={values} setValues={changeValues} options={Object.fromEntries(Object.keys(options.social_limits).map(item => [item, item]))} required /><Field label="Target characters" name="target_characters" type="number" min="1" max={options.social_limits[values.social_platform]} values={values} setValues={changeValues} required help={`Workspace limit: ${options.social_limits[values.social_platform]} characters.`} /></div> : <Field label="Target word count" name="target_words" type="number" min={values.content_type === 'BLOG' ? 300 : 50} max={values.content_type === 'BLOG' ? 2500 : 1500} values={values} setValues={changeValues} required help={values.content_type === 'BLOG' ? '300 to 2500 words.' : '50 to 1500 words.'} />}
      <div className="form-columns"><Field label="Primary keyword" name="primary_keyword" values={values} setValues={changeValues} maxLength="100" /><Field label="Secondary keywords" name="secondary_keywords" area values={values} setValues={changeValues} help="One keyword per line, up to 10 unique keywords." /></div>
      <Field label="Call to action" name="call_to_action" values={values} setValues={changeValues} maxLength="300" /><Field label="Required facts or talking points" name="talking_points" area values={values} setValues={changeValues} maxLength="5000" />
      <h2>Sources and review</h2><Choice label="Research mode" name="research_mode" values={values} setValues={changeValues} options={{ PROVIDED_ONLY: 'Provided sources only', PUBLIC_WEB: 'Provided sources and public web' }} required /><Field label="Reference URLs" name="reference_urls" area values={values} setValues={changeValues} help="One public HTTP or HTTPS URL per line, up to 10. Save the brief, then use the Source library below to retrieve these links or upload files." />
      {values.research_mode === 'PROVIDED_ONLY' && !lines(values.reference_urls).length && <p className="helper-text">You can save now, but at least one usable source will be needed before generation.</p>}
      <Field label="Additional instructions" name="additional_instructions" area values={values} setValues={changeValues} maxLength="5000" /><Choice label="Assigned reviewer" name="reviewer_id" values={values} setValues={changeValues} options={reviewerOptions} />
      </fieldset>{canEdit && <div className="button-row"><button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : 'Save brief'}</button>{dirty && <span className="helper-text">Unsaved changes</span>}</div>}
      {brief?.brand_snapshot?.captured_at && <p className="helper-text">Brand settings captured {new Date(brief.brand_snapshot.captured_at).toLocaleString()}. Use “Apply current brand defaults” and save to refresh the captured profile.</p>}
      </>}
    </form></section>
    {editing && brief && <><GenerationPanel key={`generation-${brief.id}`} brief={brief} dirty={dirty} editable={workspaceEditable} onBriefChanged={briefResource.reload} /><SourcePanel key={brief.id} brief={brief} canEdit={canEdit} /></>}
  </>
}
