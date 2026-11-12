import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { mutate } from '../lib/api'
import { Field, Feedback, useAction, useResource, lines } from './WorkspaceParts'

export function ClientForm({ client, onSaved }) {
  const [values, setValues] = useState({ name: '', industry: '', website: '' })
  const action = useAction()
  useEffect(() => setValues({ name: client?.name || '', industry: client?.industry || '', website: client?.website || '' }), [client])
  async function submit(event) {
    event.preventDefault()
    const result = await action.run(() => mutate(client ? 'patch' : 'post', client ? `/clients/${client.id}` : '/admin/clients', { ...values, ...(client ? { version: client.version } : {}) }), client ? 'Client saved.' : 'Client created.')
    if (result) onSaved(result.data.client)
  }
  return <form className="form-stack" onSubmit={submit}><Feedback action={action} /><Field label="Client name" name="name" values={values} setValues={setValues} required maxLength="200" /><Field label="Industry" name="industry" values={values} setValues={setValues} maxLength="200" /><Field label="Website" name="website" type="url" values={values} setValues={setValues} maxLength="2048" placeholder="https://example.com" /><button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : client ? 'Save client' : 'Create client'}</button></form>
}

export function NewClientPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  if (user.role !== 'ADMIN') return <div className="page-heading"><h1>Administrator access required</h1></div>
  return <><Link className="back-link" to="/clients">← Clients</Link><div className="page-heading"><h1>New client</h1><p>Create a workspace for a client, then add its brand profile and projects.</p></div><section className="editor-panel narrow"><ClientForm onSaved={client => navigate(`/clients/${client.id}`)} /></section></>
}

function BrandForm({ client, canEdit, reload }) {
  const profile = client.brand_profile
  const [values, setValues] = useState({})
  const action = useAction()
  useEffect(() => setValues({ default_audience: profile?.default_audience || '', default_tone: profile?.default_tone || '',
    preferred_terms: (profile?.guidelines?.preferred_terms || []).join('\n'), prohibited_terms: (profile?.guidelines?.prohibited_terms || []).join('\n'),
    claims_requiring_evidence: profile?.guidelines?.claims_requiring_evidence || '', approved_facts: profile?.guidelines?.approved_facts || '', sample_copy: profile?.guidelines?.sample_copy || '' }), [profile])
  async function submit(event) {
    event.preventDefault()
    const result = await action.run(() => mutate('put', `/clients/${client.id}/brand-profile`, { ...values, version: profile?.version || 0, preferred_terms: lines(values.preferred_terms), prohibited_terms: lines(values.prohibited_terms) }), 'Brand profile saved.')
    if (result) reload()
  }
  return <><div className="section-heading"><h2>Brand voice and guidelines</h2><button type="button" className="text-button" onClick={() => { action.clear(); reload() }}>Reload saved version</button></div><p className="helper-text">These defaults help writers create consistent briefs. Existing brief snapshots stay unchanged when this profile is edited.</p><Feedback action={action} /><form onSubmit={submit} className="form-stack"><fieldset className="plain-fieldset" disabled={!canEdit || action.busy}><Field label="Default audience" name="default_audience" area values={values} setValues={setValues} maxLength="500" /><Field label="Default tone" name="default_tone" values={values} setValues={setValues} maxLength="300" placeholder="Professional and approachable" /><div className="form-columns"><Field label="Preferred terms" name="preferred_terms" area values={values} setValues={setValues} help="One term per line, up to 50 terms." /><Field label="Prohibited terms" name="prohibited_terms" area values={values} setValues={setValues} help="One term per line, up to 50 terms." /></div><Field label="Claims requiring evidence" name="claims_requiring_evidence" area values={values} setValues={setValues} maxLength="5000" /><Field label="Approved product facts" name="approved_facts" area values={values} setValues={setValues} maxLength="10000" /><Field label="Sample copy" name="sample_copy" area values={values} setValues={setValues} maxLength="10000" /></fieldset>{canEdit && <button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : 'Save brand profile'}</button>}</form></>
}

export function ProjectForm({ clientId, project, onSaved }) {
  const [values, setValues] = useState({ name: project?.name || '', description: project?.description || '' })
  const action = useAction()
  async function submit(event) {
    event.preventDefault()
    const result = await action.run(() => mutate(project ? 'patch' : 'post', project ? `/projects/${project.id}` : `/clients/${clientId}/projects`, { ...values, ...(project ? { version: project.version } : {}) }), 'Project saved.')
    if (result) onSaved(result.data.project)
  }
  return <form className="form-stack" onSubmit={submit}><Feedback action={action} /><Field label="Project name" name="name" values={values} setValues={setValues} required maxLength="200" /><Field label="Project description" name="description" area values={values} setValues={setValues} maxLength="5000" /><button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : project ? 'Save project' : 'Create project'}</button></form>
}

export default function ClientWorkspace() {
  const { id } = useParams()
  const { user } = useAuth()
  const resource = useResource(`/clients/${id}`)
  const projects = useResource(`/projects?client_id=${id}`)
  const action = useAction()
  const [tab, setTab] = useState('projects')
  const [creatingProject, setCreatingProject] = useState(false)
  const navigate = useNavigate()
  const client = resource.data?.client
  const canEdit = client && !client.is_archived && ['ADMIN', 'WRITER'].includes(user.role)
  function reload() { resource.reload(); projects.reload() }
  async function archive() {
    const result = await action.run(() => mutate('patch', `/clients/${id}`, { version: client.version, is_archived: !client.is_archived }), client.is_archived ? 'Client restored.' : 'Client archived.')
    if (result) reload()
  }
  return <><Link className="back-link" to="/clients">← Clients</Link><Feedback action={action} resource={resource} />{!client && resource.loading && <p role="status">Loading client…</p>}{client && <>
    <div className="page-heading"><span className="eyebrow">Client workspace</span><div className="title-actions"><h1>{client.name}</h1>{user.role === 'ADMIN' && <button type="button" className="button secondary" disabled={action.busy} onClick={archive}>{client.is_archived ? 'Restore client' : 'Archive client'}</button>}</div><p>{client.industry || 'Client workspace'}{client.website && <> · <a href={client.website} target="_blank" rel="noreferrer">Website ↗</a></>}</p></div>
    {client.is_archived && <p className="archive-notice">This client is archived. Its projects and briefs remain available to read. Restore the client to make changes.</p>}
    {user.role === 'REVIEWER' && <p className="helper-text">You have read-only access to client settings, projects, and briefs.</p>}
    <div className="admin-tabs">{[['projects', 'Projects'], ['brand', 'Brand profile'], ['details', 'Client details']].map(([key, label]) => <button type="button" key={key} aria-pressed={tab === key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>{label}</button>)}</div>
    {tab === 'projects' && <><Feedback resource={projects} /><div className="section-heading"><h2>Projects</h2>{canEdit && <button className="button primary" type="button" onClick={() => setCreatingProject(value => !value)}>{creatingProject ? 'Cancel new project' : 'New project'}</button>}</div>{creatingProject && canEdit && <section className="editor-panel narrow"><ProjectForm clientId={id} onSaved={project => navigate(`/projects/${project.id}`)} /></section>}
      {projects.data?.projects.length === 0 && <section className="empty-panel"><h2>No projects yet</h2><p>Create a project to organize this client’s content briefs.</p></section>}<div className="client-grid">{projects.data?.projects.map(project => <Link className="client-card" to={`/projects/${project.id}`} key={project.id}><span className="tag">{project.is_archived ? 'Archived' : 'Active'}</span><h2>{project.name}</h2><p>{project.description || 'Content project'}</p><span className="text-link">{project.briefs_count} briefs · Open project →</span></Link>)}</div></>}
    {tab === 'brand' && <section className="editor-panel"><BrandForm client={client} canEdit={canEdit} reload={reload} /></section>}
    {tab === 'details' && <section className="editor-panel narrow"><div className="section-heading"><h2>Client details</h2><button type="button" className="text-button" onClick={reload}>Reload saved version</button></div>{user.role === 'ADMIN' && !client.is_archived ? <ClientForm client={client} onSaved={reload} /> : <dl className="service-list"><div><dt>Name</dt><dd>{client.name}</dd></div><div><dt>Industry</dt><dd>{client.industry || 'Not set'}</dd></div><div><dt>Website</dt><dd>{client.website || 'Not set'}</dd></div></dl>}</section>}
  </>}</>
}
