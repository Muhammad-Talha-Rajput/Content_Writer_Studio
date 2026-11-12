import { useCallback, useEffect, useState } from 'react'
import { api, mutate, errorMessage } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

function ShareLink({ link, kind, onClose }) {
  const [copied, setCopied] = useState(false)
  const [error, setError] = useState('')
  async function copy() {
    try { await navigator.clipboard.writeText(link); setCopied(true) }
    catch { setError('Select and copy the link below.') }
  }
  return <section className="share-panel" aria-label={`${kind} link`}><div className="section-heading"><h2>{kind} link created</h2><button className="text-button" type="button" onClick={onClose}>Dismiss</button></div><p>Share this link only with the intended user. It works once and is shown here only until you leave this page.</p><label className="sr-only" htmlFor="share-link">{kind} link</label><input id="share-link" readOnly value={link} onFocus={event => event.target.select()} /><button type="button" className="button secondary" onClick={copy}>{copied ? 'Copied' : 'Copy link'}</button>{error && <p role="status">{error}</p>}</section>
}

function UserAccess({ user, clients, busy, save }) {
  const [selected, setSelected] = useState(user.clients.map(client => client.id))
  useEffect(() => setSelected(user.clients.map(client => client.id)), [user])
  if (user.role === 'ADMIN') return <span className="helper-text">All clients</span>
  return <details className="client-assignment"><summary>{user.clients.length ? `${user.clients.length} assigned · edit access` : 'No clients · assign access'}</summary><div className="assignment-options">{clients.length === 0 && <p className="helper-text">Add a client first.</p>}{clients.map(client => <label className="checkbox-label" key={client.id}><input type="checkbox" checked={selected.includes(client.id)} disabled={busy || !user.is_active} onChange={event => setSelected(value => event.target.checked ? [...value, client.id] : value.filter(id => id !== client.id))} />{client.name}</label>)}<button className="button secondary" type="button" disabled={busy || !user.is_active} onClick={() => save(user.id, selected)}>Save client access</button></div></details>
}

export default function AdminPage() {
  const { user: currentUser } = useAuth()
  const [tab, setTab] = useState('people')
  const [data, setData] = useState({ users: [], clients: [], invitations: [], events: [], lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [share, setShare] = useState(null)
  const [clientName, setClientName] = useState('')
  const [invite, setInvite] = useState({ email: '', role: 'WRITER', client_ids: [] })
  const [auditPage, setAuditPage] = useState(1)

  const load = useCallback(async () => {
    const [users, clients, invitations, events] = await Promise.all([
      api.get('/admin/users'), api.get('/clients'), api.get('/admin/invitations'), api.get('/admin/audit-events', { params: { page: auditPage } }),
    ])
    setData({ users: users.data.users, clients: clients.data.clients, invitations: invitations.data.invitations,
      events: events.data.data, lastPage: events.data.last_page })
  }, [auditPage])

  useEffect(() => {
    let active = true
    load().catch(failure => { if (active) setError(errorMessage(failure)) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [load, tab])

  async function action(work, success) {
    setBusy(true)
    setError('')
    setMessage('')
    try { await work(); await load(); setMessage(success) }
    catch (failure) { setError(errorMessage(failure)) }
    finally { setBusy(false) }
  }

  async function inviteUser(event) {
    event.preventDefault()
    await action(async () => {
      const response = await mutate('post', '/admin/invitations', invite)
      setShare({ kind: 'Invitation', link: response.data.invitation_url })
      setInvite({ email: '', role: 'WRITER', client_ids: [] })
    }, 'Invitation created. Share the link with your teammate.')
  }

  function invitationState(invitation) {
    if (invitation.accepted_at) return 'Accepted'
    if (invitation.revoked_at) return 'Revoked'
    return new Date(invitation.expires_at) < new Date() ? 'Expired' : 'Pending'
  }

  function target(event) {
    return event.metadata?.label || data.users.find(user => user.id === event.target_id)?.name
      || data.clients.find(client => client.id === event.target_id)?.name
      || data.invitations.find(invitation => invitation.id === event.target_id)?.email || 'Workspace record'
  }

  return <><div className="page-heading"><span className="eyebrow">Administration</span><h1>Team and access</h1><p>Invite your team, set responsibilities, and decide which clients each person can access.</p></div>
    <div className="admin-tabs" aria-label="Administration sections">{[['people', 'People and clients'], ['invitations', 'Invitations'], ['activity', 'Activity']].map(([key, label]) => <button type="button" key={key} aria-pressed={tab === key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>{label}</button>)}</div>
    {error && <p className="error-message" role="alert">{error}</p>}{message && <p className="success-message" role="status">{message}</p>}
    {share && <ShareLink key={share.link} {...share} onClose={() => setShare(null)} />}
    {loading ? <p role="status">Loading team…</p> : <>
      {tab === 'people' && <><section className="admin-panel"><div className="section-heading"><h2>People <span className="count-label">{data.users.length}</span></h2><button type="button" className="button primary" onClick={() => setTab('invitations')}>Invite a teammate</button></div><p className="helper-text">Changing a role or deactivating an account ends that person’s sessions. Administrators have access to all clients.</p>
        <div className="table-scroll"><table className="team-table"><thead><tr><th>Person</th><th>Role</th><th>Client access</th><th>Account</th></tr></thead><tbody>{data.users.map(user => <tr key={user.id}><td><strong>{user.name}</strong><span className="cell-subtitle">{user.email}</span>{user.id === currentUser.id && <span className="tag">Your account</span>}</td><td><label className="sr-only" htmlFor={`role-${user.id}`}>Role for {user.name}</label><select id={`role-${user.id}`} value={user.role} disabled={busy || user.id === currentUser.id} onChange={event => action(() => mutate('patch', `/admin/users/${user.id}`, { role: event.target.value }), 'Role updated.')}><option value="ADMIN">Administrator</option><option value="WRITER">Writer</option><option value="REVIEWER">Reviewer</option></select></td><td><UserAccess user={user} clients={data.clients} busy={busy} save={(id, ids) => action(() => mutate('put', `/admin/users/${id}/clients`, { client_ids: ids }), 'Client access saved.')} /></td><td><span className={`account-badge ${user.is_active ? 'is-active' : ''}`}>{user.is_active ? 'Active' : 'Inactive'}</span><div className="row-actions">{user.id !== currentUser.id && <button type="button" disabled={busy} className="text-button" onClick={() => action(() => mutate('patch', `/admin/users/${user.id}`, { is_active: !user.is_active }), user.is_active ? 'Account deactivated.' : 'Account reactivated.')}>{user.is_active ? 'Deactivate' : 'Reactivate'}</button>}{user.is_active && <button type="button" disabled={busy} className="text-button" onClick={() => action(async () => { const response = await mutate('post', `/admin/users/${user.id}/recovery-link`); setShare({ kind: 'Recovery', link: response.data.recovery_url }) }, 'Recovery link created. It expires in 60 minutes.')}>Recovery link</button>}</div></td></tr>)}</tbody></table></div>
      </section><section className="admin-panel"><h2>Clients for assignments</h2><p className="helper-text">Create a client record here to assign access. Full client details, brand profiles, and project tools are available under Clients.</p><form className="inline-form" onSubmit={event => { event.preventDefault(); action(async () => { await mutate('post', '/admin/clients', { name: clientName }); setClientName('') }, 'Client created.') }}><label>Client name<input value={clientName} maxLength="200" required onChange={event => setClientName(event.target.value)} /></label><button className="button secondary" disabled={busy}>Add client</button></form><div className="client-name-list">{data.clients.map(client => <span className="tag" key={client.id}>{client.name}</span>)}</div></section></>}
      {tab === 'invitations' && <><section className="admin-panel"><h2>Invite a teammate</h2><p className="helper-text">Invitation links last seven days and can be used once. Reinviting the same email replaces its earlier link. You share links yourself; no invitation email is sent automatically.</p><form className="form-stack invite-form" onSubmit={inviteUser}><label>Email address<input type="email" required maxLength="254" value={invite.email} onChange={event => setInvite(value => ({ ...value, email: event.target.value }))} /></label><label>Role<select value={invite.role} onChange={event => setInvite(value => ({ ...value, role: event.target.value }))}><option value="WRITER">Writer</option><option value="REVIEWER">Reviewer</option><option value="ADMIN">Administrator</option></select></label>{invite.role !== 'ADMIN' && <fieldset><legend>Initial client access</legend>{data.clients.length === 0 && <p className="helper-text">You can assign clients after creating them.</p>}{data.clients.map(client => <label className="checkbox-label" key={client.id}><input type="checkbox" checked={invite.client_ids.includes(client.id)} onChange={event => setInvite(value => ({ ...value, client_ids: event.target.checked ? [...value.client_ids, client.id] : value.client_ids.filter(id => id !== client.id) }))} />{client.name}</label>)}</fieldset>}<button className="button primary" disabled={busy}>{busy ? 'Creating…' : 'Create invitation'}</button></form></section>
        <section className="admin-panel"><h2>Invitation history</h2>{data.invitations.length === 0 ? <p className="helper-text">No invitations yet.</p> : <div className="table-scroll"><table><thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>{data.invitations.map(invitation => <tr key={invitation.id}><td>{invitation.email}</td><td>{invitation.role.toLowerCase()}</td><td>{invitationState(invitation)}</td><td>{invitationState(invitation) === 'Pending' && <button type="button" className="text-button" disabled={busy} onClick={() => action(() => mutate('delete', `/admin/invitations/${invitation.id}`), 'Invitation revoked.')}>Revoke</button>}</td></tr>)}</tbody></table></div>}</section></>}
      {tab === 'activity' && <section className="admin-panel"><h2>Access activity</h2><div className="table-scroll"><table><thead><tr><th>Action</th><th>By</th><th>Record</th><th>When</th></tr></thead><tbody>{data.events.map(event => <tr key={event.id}><td>{event.event_type.replaceAll('.', ' ').replaceAll('_', ' ')}</td><td>{event.actor?.name || 'System'}</td><td>{target(event)}</td><td>{new Date(event.created_at).toLocaleString()}</td></tr>)}</tbody></table></div><div className="pagination"><button type="button" className="button secondary" disabled={auditPage <= 1} onClick={() => setAuditPage(value => value - 1)}>Previous</button><span>Page {auditPage} of {data.lastPage}</span><button type="button" className="button secondary" disabled={auditPage >= data.lastPage} onClick={() => setAuditPage(value => value + 1)}>Next</button></div></section>}
    </>}
  </>
}
