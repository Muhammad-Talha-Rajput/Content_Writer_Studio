import { useEffect, useState } from 'react'
import axios from 'axios'
import { NavLink, Route, Routes, Link, Navigate, useLocation } from 'react-router-dom'
import { api } from './lib/api'
import { errorMessage } from './lib/api'
import { useAuth } from './auth/AuthContext'
import AuthPage, { AuthFrame } from './pages/AuthPages'
import AdminPage from './pages/AdminPage'
import ClientsPage from './pages/ClientsPage'
import ClientWorkspace, { NewClientPage } from './pages/ClientWorkspace'
import ProjectsPage, { ProjectDetail, BriefsPage } from './pages/ProjectPages'
import BriefPage from './pages/BriefPage'
import AccountPage from './pages/AccountPage'
import AiSettingsPage from './pages/AiSettingsPage'
import ContentLibraryPage from './pages/ContentLibraryPage'
import { Feedback, useResource } from './pages/WorkspaceParts'

const workflow = [
  ['01', 'Brief', 'Give every piece a clear audience, objective, and brand voice.'],
  ['02', 'Research and draft', 'Turn approved sources into a structured first draft.'],
  ['03', 'Review', 'Keep your team in control of edits and approval.'],
  ['04', 'Export', 'Take the approved version into your publishing workflow.'],
]

function useWorkspaceHealth() {
  const [attempt, setAttempt] = useState(0)
  const [health, setHealth] = useState({ state: 'loading', checkedAt: null })

  useEffect(() => {
    const controller = new AbortController()
    api.get('/health', { signal: controller.signal })
      .then(({ data }) => setHealth({
        state: data.status === 'ok' && data.checks?.database === 'ok' ? 'ready' : 'unavailable',
        checkedAt: data.checked_at,
      }))
      .catch((error) => {
        if (!axios.isCancel(error)) setHealth({ state: 'unavailable', checkedAt: null })
      })
    return () => controller.abort()
  }, [attempt])

  function refresh() {
    setHealth({ state: 'loading', checkedAt: null })
    setAttempt((value) => value + 1)
  }
  return { ...health, refresh }
}

function Connection({ health }) {
  const labels = { loading: 'Checking connection', ready: 'Workspace connected', unavailable: 'Connection unavailable' }
  return <div className="connection" role="status" aria-live="polite"><span className={`status-dot ${health.state}`} aria-hidden="true" />{labels[health.state]}</div>
}

function Overview({ health }) {
  const { user } = useAuth()
  const dashboard = useResource('/dashboard')
  const counts = dashboard.data?.counts
  return <>
    <div className="page-heading"><span className="eyebrow">Your workspace</span><h1>Content starts with a clear brief.</h1><p>A shared place for Grands Digital to plan content, develop drafts, and bring every piece through review.</p></div>
    <section className="welcome-panel" aria-labelledby="welcome-heading"><div><span className="eyebrow">Welcome, {user.name}</span><h2 id="welcome-heading">Your content workspace is ready.</h2><p>{user.role === 'ADMIN' ? 'Set up client brands, organize projects, and save the briefs your content team will use.' : 'Open your assigned clients to view brand guidelines, projects, and content briefs.'} Open a saved brief to attach reference files and website sources. Use a saved brief to generate a draft with your configured AI provider.</p><Link className="button primary" to="/clients">{user.role === 'REVIEWER' ? 'View client workspaces' : 'Open clients and projects'} <span aria-hidden="true">↗</span></Link></div><div className="workflow-preview" aria-label="Planned content workflow"><span>FROM IDEA TO APPROVAL</span><p>Brief</p><i aria-hidden="true">↓</i><p>Draft</p><i aria-hidden="true">↓</i><p>Human review</p></div></section>
    <section aria-labelledby="workflow-heading" className="workflow-section"><div className="section-heading"><h2 id="workflow-heading">Your content workflow</h2><span className="tag">Briefs, sources and AI drafts</span></div><div className="workflow-grid">{workflow.map(([number, title, text]) => <article className="workflow-card" key={number}><span className="step-number">{number}</span><h3>{title}</h3><p>{text}</p></article>)}</div></section>
    <section className="workspace-section"><div className="section-heading"><h2>Workspace at a glance</h2><Link className="text-link" to="/library">Open content library →</Link></div><Feedback resource={dashboard} />{counts && <div className="dashboard-stat-grid">{[['Active projects', counts.projects], ['Content briefs', counts.briefs], ['In review', counts.in_review], ['Approved', counts.approved], ['My pending reviews', counts.pending_reviews], ['Active AI runs', counts.active_runs]].map(([label, value]) => <article className="dashboard-stat" key={label}><span>{label}</span><strong>{value}</strong></article>)}</div>}
      {dashboard.data && <div className="dashboard-columns"><section><div className="section-heading"><h3>Recent content</h3><Link to="/library">View all</Link></div>{dashboard.data.recent_versions.length ? dashboard.data.recent_versions.map(version => <Link className="dashboard-row" key={version.id} to={`/briefs/${version.brief_id}`}><span><strong>{version.title}</strong><small>{version.brief?.project?.client?.name} · {version.brief?.topic}</small></span><span className="tag">V{version.version_no}</span></Link>) : <p className="helper-text">No saved drafts yet.</p>}</section>
      <section><div className="section-heading"><h3>Recent runs</h3></div>{dashboard.data.recent_runs.length ? dashboard.data.recent_runs.map(run => <Link className="dashboard-row" key={run.id} to={`/briefs/${run.brief_id}`}><span><strong>{run.brief?.topic || 'Content run'}</strong><small>{new Date(run.created_at).toLocaleString()}</small></span><span className="tag">{run.status}</span></Link>) : <p className="helper-text">No AI runs yet.</p>}</section></div>}
      {dashboard.data?.pending_reviews.length > 0 && <section className="pending-review-list"><div className="section-heading"><h3>Waiting for your review</h3><Link to="/library?status=IN_REVIEW">Open queue</Link></div>{dashboard.data.pending_reviews.map(item => <Link className="dashboard-row" key={item.id} to={`/briefs/${item.version?.brief_id}`}><span><strong>{item.version?.title}</strong><small>{item.submitter?.name} · Version {item.version?.version_no}</small></span><span className="tag">Review</span></Link>)}</section>}</section>
    <footer className="page-footer"><Connection health={health} /><span>Grands Digital · Content Studio</span></footer>
  </>
}

function Setup({ health }) {
  return <>
    <div className="page-heading"><span className="eyebrow">Development workspace</span><h1>Connection status</h1><p>Check that the workspace can reach the application and its database.</p></div>
    <section className="setup-panel" aria-labelledby="status-heading"><div className="section-heading"><h2 id="status-heading">Workspace services</h2><Connection health={health} /></div><dl className="service-list"><div><dt>Web interface</dt><dd>Ready</dd></div><div><dt>Application and database</dt><dd>{health.state === 'ready' ? 'Connected' : health.state === 'loading' ? 'Checking…' : 'Unavailable'}</dd></div><div><dt>Writing agents</dt><dd className="muted">Configure in AI providers</dd></div><div><dt>Authentication and access</dt><dd>Session protected</dd></div></dl>
      {health.state === 'unavailable' && <p className="error-message" role="alert">The application or database could not be reached. Check that XAMPP MySQL and the backend server are running, then try again.</p>}
      {health.checkedAt && <p className="last-checked">Last checked <time dateTime={health.checkedAt}>{new Date(health.checkedAt).toLocaleTimeString()}</time></p>}
      <button className="button primary" type="button" onClick={health.refresh} disabled={health.state === 'loading'}>{health.state === 'loading' ? 'Checking…' : 'Check again'}</button>
    </section><p className="setup-note">This screen verifies the foundation only. No content is generated and no user accounts are created by this check.</p>
  </>
}

function Workspace() {
  const auth = useAuth()
  const [logoutError, setLogoutError] = useState('')
  const [signingOut, setSigningOut] = useState(false)
  const health = useWorkspaceHealth()
  async function logout() {
    setSigningOut(true)
    setLogoutError('')
    try { await auth.logout() }
    catch (failure) { setLogoutError(errorMessage(failure)) }
    finally { setSigningOut(false) }
  }
  return <div className="app-shell"><a className="skip-link" href="#main-content">Skip to content</a>
    <aside className="sidebar"><Link className="brand" to="/" aria-label="Grands Digital Content Studio home"><span className="brand-mark">G</span><span>grands digital<small>CONTENT STUDIO</small></span></Link><nav aria-label="Main navigation"><NavLink to="/" end><span aria-hidden="true">◫</span> Overview</NavLink><NavLink to="/clients"><span aria-hidden="true">▤</span> Clients</NavLink><NavLink to="/projects"><span aria-hidden="true">▦</span> Projects</NavLink><NavLink to="/briefs"><span aria-hidden="true">≡</span> Content briefs</NavLink><NavLink to="/library"><span aria-hidden="true">▣</span> Content library</NavLink>{auth.user.role === 'ADMIN' && <NavLink to="/admin"><span aria-hidden="true">♧</span> Team and access</NavLink>}{auth.user.role === 'ADMIN' && <NavLink to="/ai-settings"><span aria-hidden="true">✧</span> AI providers</NavLink>}<NavLink to="/account"><span aria-hidden="true">○</span> My account</NavLink><NavLink to="/setup"><span aria-hidden="true">◎</span> Connection status</NavLink></nav><div className="sidebar-note"><span className="sidebar-note-label">Built for your team</span><p>Clear briefs.<br />A consistent brand voice.<br />Human approval.</p></div></aside>
    <div className="main-shell"><header className="topbar"><span>Grands Digital / Content Studio</span><div className="user-menu"><Link to="/account"><strong>{auth.user.name}</strong><small>{auth.user.role.toLowerCase()}</small></Link><button type="button" className="text-button" disabled={signingOut} onClick={logout}>{signingOut ? 'Signing out…' : 'Sign out'}</button></div></header><main id="main-content" tabIndex="-1">{logoutError && <p className="error-message" role="alert">{logoutError}</p>}<Routes><Route path="/" element={<Overview health={health} />} /><Route path="/setup" element={<Setup health={health} />} /><Route path="/clients" element={<ClientsPage />} /><Route path="/clients/new" element={<NewClientPage />} /><Route path="/clients/:id" element={<ClientWorkspace />} /><Route path="/projects" element={<ProjectsPage />} /><Route path="/projects/:id" element={<ProjectDetail />} /><Route path="/projects/:projectId/briefs/new" element={<BriefPage />} /><Route path="/briefs" element={<BriefsPage />} /><Route path="/briefs/new" element={<BriefPage />} /><Route path="/briefs/:id" element={<BriefPage />} /><Route path="/library" element={<ContentLibraryPage />} /><Route path="/ai-settings" element={auth.user.role === 'ADMIN' ? <AiSettingsPage /> : <Navigate to="/" replace />} /><Route path="/account" element={<AccountPage />} /><Route path="/admin" element={auth.user.role === 'ADMIN' ? <AdminPage /> : <div className="page-heading"><h1>Administrator access required</h1><p>Your account does not have access to team administration.</p><Link className="button primary" to="/">Back to overview</Link></div>} /><Route path="*" element={<div className="page-heading"><h1>Page not found</h1><p>This page is not part of the current workspace.</p><Link className="button primary" to="/">Back to overview</Link></div>} /></Routes></main></div>
  </div>
}

function Studio() {
  const auth = useAuth()
  const location = useLocation()
  if (auth.loading) return <AuthFrame><h2>Opening your workspace…</h2><p role="status">Checking your session.</p></AuthFrame>
  if (auth.error) return <AuthFrame><h2>Workspace unavailable</h2><p className="error-message" role="alert">{auth.error}</p><button className="button primary" onClick={() => auth.refresh()}>Try again</button></AuthFrame>
  const publicPages = { '/login': 'login', '/welcome': 'welcome', '/accept-invitation': 'invite', '/forgot-password': 'forgot', '/reset-password': 'reset' }
  if (publicPages[location.pathname]) return <AuthPage key={location.pathname + location.search} mode={publicPages[location.pathname]} />
  if (!auth.user) return <Navigate to={auth.setup_available ? '/welcome' : '/login'} replace />
  return <Workspace />
}

export default Studio
