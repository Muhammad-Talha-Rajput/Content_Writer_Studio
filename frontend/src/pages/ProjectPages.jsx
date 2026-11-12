import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { mutate } from '../lib/api'
import { ArchiveFilter, Feedback, useAction, useResource } from './WorkspaceParts'
import { ProjectForm } from './ClientWorkspace'

export default function ProjectsPage() {
  const resource = useResource('/projects')
  const [archived, setArchived] = useState(false)
  const { user } = useAuth()
  const projects = resource.data?.projects.filter(project => archived || (!project.is_archived && !project.client.is_archived))
  return <><div className="page-heading"><span className="eyebrow">Content planning</span><div className="title-actions"><h1>Projects</h1>{user.role !== 'REVIEWER' && <Link className="button primary" to="/clients">Choose a client to add a project</Link>}</div><p>Organize content briefs by client and campaign.</p></div><ArchiveFilter checked={archived} onChange={setArchived} /><Feedback resource={resource} />{resource.loading && !resource.data && <p role="status">Loading projects…</p>}{projects?.length === 0 && <section className="empty-panel"><h2>No projects to show</h2><p>Open a client workspace to create a project, or include archived work.</p></section>}<div className="client-grid">{projects?.map(project => <Link className="client-card" key={project.id} to={`/projects/${project.id}`}><span className="eyebrow">{project.client.name}</span><h2>{project.name}</h2><p>{project.description || 'Content project'}</p><span className="tag">{project.is_archived || project.client.is_archived ? 'Archived work' : `${project.briefs_count} briefs`}</span></Link>)}</div></>
}

export function ProjectDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const resource = useResource(`/projects/${id}`)
  const action = useAction()
  const [editing, setEditing] = useState(false)
  const [archived, setArchived] = useState(false)
  const project = resource.data?.project
  const canManage = project && !project.client.is_archived && user.role !== 'REVIEWER'
  async function archive() {
    const result = await action.run(() => mutate('patch', `/projects/${id}`, { version: project.version, is_archived: !project.is_archived }), project.is_archived ? 'Project restored.' : 'Project archived.')
    if (result) { setEditing(false); resource.reload() }
  }
  return <><Feedback action={action} resource={resource} />{!project && resource.loading && <p role="status">Loading project…</p>}{project && <><Link className="back-link" to={`/clients/${project.client_id}`}>← {project.client.name}</Link><div className="page-heading"><span className="eyebrow">Project</span><div className="title-actions"><h1>{project.name}</h1><div className="button-row">{canManage && !project.is_archived && <button className="button secondary" type="button" onClick={() => setEditing(value => !value)}>{editing ? 'Cancel editing' : 'Edit project'}</button>}{canManage && <button className="button secondary" type="button" disabled={action.busy} onClick={archive}>{project.is_archived ? 'Restore project' : 'Archive project'}</button>}</div></div><p>{project.description || 'Content briefs for this project.'}</p></div>
    {(project.is_archived || project.client.is_archived) && <p className="archive-notice">Archived work is read-only. Restore the client and project before changing briefs.</p>}
    {editing && canManage && !project.is_archived && <section className="editor-panel narrow"><button type="button" className="text-button" onClick={() => { setEditing(false); resource.reload() }}>Reload saved version</button><ProjectForm key={project.version} project={project} onSaved={() => { setEditing(false); resource.reload() }} /></section>}
    <section className="workspace-section"><div className="section-heading"><h2>Content briefs</h2>{canManage && !project.is_archived && <Link className="button primary" to={`/projects/${id}/briefs/new`}>New brief</Link>}</div><ArchiveFilter checked={archived} onChange={setArchived} /><BriefCards briefs={project.briefs.filter(brief => archived || !brief.is_archived)} empty="No briefs yet. Create a brief to define the topic, audience, format, and writing instructions." /></section>
  </>}</>
}

export function BriefCards({ briefs, empty }) {
  return briefs.length === 0 ? <section className="empty-panel"><h2>No briefs to show</h2><p>{empty}</p></section> : <div className="brief-list">{briefs.map(brief => <Link className="brief-row" key={brief.id} to={`/briefs/${brief.id}`}><div><span className="eyebrow">{brief.project?.client?.name || brief.content_type.toLowerCase()}</span><h3>{brief.topic}</h3><p>{brief.project?.name || 'Saved brief'} · {new Date(brief.updated_at).toLocaleDateString()}</p></div><span className="tag">{brief.is_archived ? 'Archived' : brief.status?.replaceAll('_', ' ') || 'Draft'}</span></Link>)}</div>
}

export function BriefsPage() {
  const { user } = useAuth()
  const resource = useResource('/briefs')
  const [archived, setArchived] = useState(false)
  const briefs = resource.data?.briefs.filter(brief => archived || (!brief.is_archived && !brief.project.is_archived && !brief.project.client.is_archived))
  return <><div className="page-heading"><span className="eyebrow">Content planning</span><div className="title-actions"><h1>Content briefs</h1>{user.role !== 'REVIEWER' && <Link className="button primary" to="/briefs/new">New brief</Link>}</div><p>Save the instructions your content team will use for research and drafting.</p></div><ArchiveFilter checked={archived} onChange={setArchived} /><Feedback resource={resource} />{resource.loading && !resource.data && <p role="status">Loading briefs…</p>}{briefs && <BriefCards briefs={briefs} empty="Create your first brief from an active project, or include archived work." />}</>
}
