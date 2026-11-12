import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Feedback, useResource } from './WorkspaceParts'

const defaultFilters = { q: '', status: '', content_type: '', client_id: '', project_id: '', include_archived: false, format: '' }

export default function ContentLibraryPage() {
  const [view, setView] = useState('content')
  const [filters, setFilters] = useState(() => ({ ...defaultFilters, status: new URLSearchParams(window.location.search).get('status') || '' }))
  const [applied, setApplied] = useState(filters)
  const [page, setPage] = useState(1)
  const clients = useResource('/clients')
  const projects = useResource('/projects')
  const params = useMemo(() => {
    const values = view === 'exports'
      ? { q: applied.q, client_id: applied.client_id, format: applied.format }
      : { q: applied.q, status: applied.status, content_type: applied.content_type, client_id: applied.client_id,
        project_id: applied.project_id, include_archived: applied.include_archived ? 1 : 0 }
    const query = new URLSearchParams(Object.entries({ ...values, page }).filter(([, value]) => value !== '' && value != null))
    return query.toString()
  }, [view, applied, page])
  const resource = useResource(view === 'exports' ? `/export-history?${params}` : `/content-library?${params}`)
  const result = view === 'exports' ? resource.data?.exports : resource.data?.briefs
  const visibleProjects = (projects.data?.projects || []).filter(project => !applied.client_id || project.client_id === applied.client_id)
  function update(name, value) { setFilters(current => ({ ...current, [name]: value })) }
  function submit(event) { event.preventDefault(); setPage(1); setApplied(filters) }
  function changeView(value) { setView(value); setPage(1) }

  return <>
    <div className="page-heading"><span className="eyebrow">Workspace</span><h1>Content library</h1><p>Search saved briefs and drafts, see their review status, and find a record of approved exports.</p></div>
    <div className="library-tabs" role="tablist" aria-label="Library view"><button type="button" role="tab" aria-selected={view === 'content'} className={view === 'content' ? 'active' : ''} onClick={() => changeView('content')}>Content</button><button type="button" role="tab" aria-selected={view === 'exports'} className={view === 'exports' ? 'active' : ''} onClick={() => changeView('exports')}>Export history</button></div>
    <form className="library-filters" onSubmit={submit}>
      <label>Search<input value={filters.q} maxLength="120" placeholder="Topic, draft, project or client" onChange={event => update('q', event.target.value)} /></label>
      <label>Client<select value={filters.client_id} onChange={event => { update('client_id', event.target.value); update('project_id', '') }}><option value="">All assigned clients</option>{clients.data?.clients.map(client => <option value={client.id} key={client.id}>{client.name}</option>)}</select></label>
      {view === 'content' ? <>
        <label>Status<select value={filters.status} onChange={event => update('status', event.target.value)}><option value="">All statuses</option>{[['DRAFT','Draft'],['IN_PROGRESS','Generating'],['READY_FOR_REVIEW','Ready for review'],['IN_REVIEW','In review'],['CHANGES_REQUESTED','Changes requested'],['APPROVED','Approved']].map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label>Content type<select value={filters.content_type} onChange={event => update('content_type', event.target.value)}><option value="">All types</option>{[['BLOG','Blog'],['SOCIAL','Social'],['EMAIL','Email'],['WEBSITE','Website'],['PRODUCT','Product']].map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label>Project<select value={filters.project_id} onChange={event => update('project_id', event.target.value)}><option value="">All projects</option>{visibleProjects.map(project => <option value={project.id} key={project.id}>{project.name}</option>)}</select></label>
        <label className="checkbox-label"><input type="checkbox" checked={filters.include_archived} onChange={event => update('include_archived', event.target.checked)} />Include archived</label>
      </> : <label>Format<select value={filters.format} onChange={event => update('format', event.target.value)}><option value="">All formats</option><option value="markdown">Markdown</option><option value="html">HTML</option></select></label>}
      <button className="button primary">Apply filters</button>
    </form>
    <Feedback resource={resource} />{resource.loading && !resource.data && <p role="status">Loading library…</p>}
    {!resource.loading && result?.data?.length === 0 && <section className="empty-panel"><h2>{view === 'exports' ? 'No exports found' : 'No content found'}</h2><p>Change the filters or search another term.</p></section>}
    {view === 'content' && result?.data?.length > 0 && <div className="library-list">{result.data.map(brief => <Link className="library-card" key={brief.id} to={`/briefs/${brief.id}`}><div className="library-card-head"><span className="eyebrow">{brief.project?.client?.name} · {brief.project?.name}</span><span className="tag">{brief.is_archived || brief.project?.is_archived || brief.project?.client?.is_archived ? 'Archived' : brief.status.replaceAll('_', ' ')}</span></div><h2>{brief.latest_version?.title || brief.topic}</h2><p>{brief.topic}</p><div className="library-meta"><span>{brief.content_type}</span><span>{brief.latest_version ? `Version ${brief.latest_version.version_no}` : 'No draft saved'}</span><span>Updated {new Date(brief.updated_at).toLocaleDateString()}</span>{brief.latest_version?.author?.name && <span>By {brief.latest_version.author.name}</span>}</div></Link>)}</div>}
    {view === 'exports' && result?.data?.length > 0 && <div className="library-list">{result.data.map(item => { const metadata = typeof item.metadata === 'string' ? JSON.parse(item.metadata) : item.metadata; return <Link className="library-card" key={item.id} to={`/briefs/${item.brief_id}`}><div className="library-card-head"><span className="eyebrow">{item.client_name} · {item.project_name}</span><span className="tag">{metadata?.format?.toUpperCase()}</span></div><h2>{item.title}</h2><p>{item.topic} · Version {item.version_no}</p><div className="library-meta"><span>Exported by {item.exported_by || 'Former team member'}</span><span>{new Date(item.created_at).toLocaleString()}</span></div></Link>})}</div>}
    {result && result.last_page > 1 && <div className="library-pagination"><button className="button secondary" disabled={result.current_page <= 1 || resource.loading} onClick={() => setPage(value => value - 1)}>Previous</button><span>Page {result.current_page} of {result.last_page} · {result.total} results</span><button className="button secondary" disabled={result.current_page >= result.last_page || resource.loading} onClick={() => setPage(value => value + 1)}>Next</button></div>}
  </>
}
