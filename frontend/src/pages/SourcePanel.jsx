import { useEffect, useRef, useState } from 'react'
import { mutate } from '../lib/api'
import { Feedback, useAction, useResource } from './WorkspaceParts'
import PdfSourcePreview from './PdfSourcePreview'

export default function SourcePanel({ brief, canEdit }) {
  const resource = useResource(`/briefs/${brief.id}/sources`)
  const action = useAction()
  const [url, setUrl] = useState('')
  const [selected, setSelected] = useState(null)
  const [previewMode, setPreviewMode] = useState('pdf')
  const [includeArchived, setIncludeArchived] = useState(false)
  const preview = useResource(selected ? `/sources/${selected}` : null)
  const reloadPreview = preview.reload
  const files = useRef()
  const sources = resource.data?.sources || []
  const pending = sources.some(source => source.parse_status === 'PENDING')
  useEffect(() => {
    if (!pending) return
    const timer = setInterval(resource.reload, 3000)
    return () => clearInterval(timer)
  }, [pending, resource.reload])
  const selectedStatus = sources.find(source => source.id === selected)?.parse_status
  useEffect(() => { if (selected) reloadPreview() }, [selected, selectedStatus, reloadPreview])

  async function upload(event) {
    event.preventDefault()
    const chosen = Array.from(files.current?.files || [])
    if (!chosen.length) return
    const result = await action.run(async () => {
      if (chosen.length > 5 || chosen.some(file => file.size > 10485760)) throw { response: { status: 422, data: { message: 'Choose up to 5 files, each no larger than 10 MB.' } } }
      for (const file of chosen) {
        const body = new FormData(); body.append('source_type', 'FILE'); body.append('file', file)
        await mutate('post', `/briefs/${brief.id}/sources`, body)
        resource.reload()
      }
      return true
    }, 'Files attached. Scanning and extraction are running.')
    // Clear even after a partial batch to avoid uploading successful files twice.
    if (files.current) files.current.value = ''
    resource.reload()
    return result
  }

  async function attachUrls(urls) {
    const existing = new Set(sources.filter(source => !source.is_archived && source.parse_status !== 'REJECTED').map(source => source.requested_url))
    const missing = [...new Set(urls)].filter(value => value && !existing.has(value))
    if (!missing.length) return
    const result = await action.run(async () => {
      for (const value of missing) {
        await mutate('post', `/briefs/${brief.id}/sources`, { source_type: 'URL', url: value })
        resource.reload()
      }
      return true
    }, 'Website links attached. Retrieval is running.')
    if (result) setUrl('')
    resource.reload()
  }

  async function archive(source) {
    const result = await action.run(() => mutate('patch', `/sources/${source.id}/archive`, { version: source.version, is_archived: !source.is_archived }), source.is_archived ? 'Source restored.' : 'Source archived. Its snapshot is retained.')
    if (result) { resource.reload(); if (selected === source.id) preview.reload() }
  }

  const savedUrls = brief.instructions.reference_urls || []
  const missingSaved = savedUrls.some(value => !sources.some(source => source.requested_url === value && !source.is_archived && source.parse_status !== 'REJECTED'))
  const snapshot = preview.data?.source
  const pdfPreview = snapshot?.source_type === 'FILE' && snapshot?.mime_type === 'application/pdf' && snapshot?.parse_status === 'READY' && snapshot?.scan_status === 'CLEAN'
  return <section id={`source-library-${brief.id}`} className="editor-panel source-panel" aria-label="Brief sources">
    <div className="section-heading"><h2>Source library</h2><button type="button" className="text-button" onClick={resource.reload}>Refresh sources</button></div>
    <p className="helper-text">Attach evidence for this brief: up to 5 files (TXT, text PDF, DOCX), 10 MB each, and 10 website links. Sources are reference material; their text cannot change the workflow instructions.</p>
    <p role="status" className={resource.data?.ready_count ? 'success-message' : 'helper-text'}>{resource.data ? `${resource.data.ready_count} ready source${resource.data.ready_count === 1 ? '' : 's'}` : 'Loading sources…'}{pending ? ' · Processing attached sources…' : ''}</p>
    {resource.data && !resource.data.ready_count && <p className="helper-text">At least one ready source will be required for provided-sources generation.</p>}
    <Feedback resource={resource} action={action} />
    {canEdit && <div className="source-intake form-columns">
      <form className="form-stack" onSubmit={upload}><label>Upload reference files<input ref={files} type="file" name="source_files" accept=".txt,.pdf,.docx" multiple required disabled={action.busy} /></label><small>Files remain private. Malware, active content, unreadable files, and PDFs without text are rejected.</small><button className="button secondary" disabled={action.busy}>Upload files</button></form>
      <form className="form-stack" onSubmit={event => { event.preventDefault(); attachUrls([url.trim()]) }}><label>Public website URL<input type="url" name="source_url" value={url} onChange={event => setUrl(event.target.value)} placeholder="https://example.com/article" required maxLength="2048" disabled={action.busy} /></label><small>Only public HTML and text pages. Each retrieval creates a fixed snapshot.</small><div className="button-row"><button className="button secondary" disabled={action.busy}>Retrieve website</button>{savedUrls.length > 0 && <button type="button" className="text-button" disabled={action.busy || !missingSaved} onClick={() => attachUrls(savedUrls)}>Retrieve saved brief links</button>}</div></form>
    </div>}
    <label className="checkbox-label archive-filter"><input type="checkbox" checked={includeArchived} onChange={event => setIncludeArchived(event.target.checked)} />Show archived sources</label>
    <div className="source-list">{sources.filter(source => includeArchived || !source.is_archived).map(source => <article className="source-card" key={source.id}>
      <div className="source-card-heading"><strong>{source.title}</strong><span className={`source-status status-${source.parse_status.toLowerCase()}`}>{source.is_archived ? 'Archived · ' : ''}{source.parse_status === 'PENDING' ? 'Processing' : source.parse_status === 'READY' ? 'Ready' : 'Rejected'}</span></div>
      <small>{source.source_type === 'FILE' ? `File · ${Math.ceil(source.byte_size / 1024)} KB` : source.final_url || source.requested_url}{source.retrieved_at ? ` · Retrieved ${new Date(source.retrieved_at).toLocaleString()}` : ''}</small>
      {source.error_message && <p className="error-message">{source.error_message}</p>}
      <div className="button-row">{source.parse_status === 'READY' && <><button className="text-button" onClick={() => { setPreviewMode('pdf'); setSelected(source.id) }}>{source.source_type === 'FILE' && source.mime_type === 'application/pdf' ? 'Preview PDF' : 'View extracted text'}</button><a className="text-link" href={`/api/sources/${source.id}/download`}>Download {source.source_type === 'FILE' ? 'original' : 'text'}</a></>}{canEdit && <button className="text-button" disabled={action.busy || (source.is_archived && source.parse_status === 'PENDING')} onClick={() => archive(source)}>{source.is_archived ? 'Restore source' : 'Archive source'}</button>}</div>
    </article>)}</div>
    {resource.data && sources.filter(source => includeArchived || !source.is_archived).length === 0 && <p className="helper-text">No sources attached yet.</p>}
    {selected && <div className="source-preview"><div className="section-heading"><h3>{snapshot?.title || 'Source preview'}</h3><button className="text-button" onClick={() => setSelected(null)}>Close preview</button></div><Feedback resource={preview} />{preview.loading && !snapshot && <p role="status">Loading source…</p>}{snapshot && <>
      {pdfPreview && <div className="source-preview-controls" role="group" aria-label="Preview format"><button type="button" className={`button ${previewMode === 'pdf' ? 'primary' : 'secondary'}`} aria-pressed={previewMode === 'pdf'} onClick={() => setPreviewMode('pdf')}>Original PDF</button><button type="button" className={`button ${previewMode === 'text' ? 'primary' : 'secondary'}`} aria-pressed={previewMode === 'text'} onClick={() => setPreviewMode('text')}>Extracted text</button></div>}
      {pdfPreview && previewMode === 'pdf' ? <><p className="helper-text">Original document with its headings, spacing, and page layout. <a className="text-link" href={`/api/sources/${snapshot.id}/preview`} target="_blank" rel="noopener noreferrer">Open PDF in a new tab</a> for browser zoom, search, or printing.</p><PdfSourcePreview key={snapshot.id} source={snapshot} /></> : <>{pdfPreview && <p className="helper-text">Plain text for reference processing. Choose Original PDF to see the document's visual layout.</p>}<div className="source-segments">{(snapshot.segments || []).map(segment => <section key={segment.ordinal}><small>{segment.locator} · Section {segment.ordinal}</small><p>{segment.text}</p></section>)}</div></>}
      <details className="source-details"><summary>Source details</summary><p className="helper-text">Immutable snapshot · SHA-256: <code>{snapshot.content_hash}</code></p></details>
    </>}</div>}
  </section>
}
