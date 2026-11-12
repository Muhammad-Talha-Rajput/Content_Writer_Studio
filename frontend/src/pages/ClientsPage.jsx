import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { ArchiveFilter, Feedback, useResource } from './WorkspaceParts'

export default function ClientsPage() {
  const { user } = useAuth()
  const resource = useResource('/clients')
  const [archived, setArchived] = useState(false)
  const clients = resource.data?.clients.filter(client => archived || !client.is_archived)
  return <><div className="page-heading"><span className="eyebrow">Client workspaces</span><div className="title-actions"><h1>Your clients</h1>{user.role === 'ADMIN' && <Link className="button primary" to="/clients/new">New client</Link>}</div><p>{user.role === 'ADMIN' ? 'Manage client details, brand guidelines, and project workspaces.' : 'View the clients assigned to you and their content workspaces.'}</p></div>
    <ArchiveFilter checked={archived} onChange={setArchived} /><Feedback resource={resource} />
    {resource.loading && !resource.data && <p role="status">Loading clients…</p>}
    {clients?.length === 0 && <section className="empty-panel"><h2>No clients to show</h2><p>{user.role === 'ADMIN' ? 'Create a client to start its brand profile and projects, or include archived work.' : 'Ask your administrator for a client assignment, or include archived work.'}</p></section>}
    <div className="client-grid">{clients?.map(client => <Link className="client-card" to={`/clients/${client.id}`} key={client.id}><span className="client-avatar" aria-hidden="true">{client.name.slice(0, 1).toUpperCase()}</span><h2>{client.name}</h2><p>{client.industry || 'Client workspace'}</p><span className="text-link">{client.is_archived ? 'Archived · open workspace' : 'Open workspace →'}</span></Link>)}</div>
  </>
}
