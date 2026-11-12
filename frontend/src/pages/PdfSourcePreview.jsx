import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export default function PdfSourcePreview({ source }) {
  const [page, setPage] = useState(1)
  const [entry, setEntry] = useState(null)
  const [failure, setFailure] = useState(null)
  useEffect(() => {
    const controller = new AbortController()
    let objectUrl
    api.get(`/sources/${source.id}/pages/${page}`, { responseType: 'blob', signal: controller.signal, timeout: 25000 })
      .then(response => {
        if (controller.signal.aborted) return
        objectUrl = URL.createObjectURL(response.data)
        setEntry({ page, objectUrl, count: Number(response.headers['x-pdf-page-count']) || 1 })
      })
      .catch(() => { if (!controller.signal.aborted) setFailure({ page }) })
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [source.id, page])
  const ready = entry?.page === page
  const failed = !ready && failure?.page === page
  return <div className="pdf-page-viewer">
    <div className="pdf-page-toolbar"><button type="button" className="button secondary" disabled={page === 1} onClick={() => setPage(value => value - 1)}>Previous page</button><span aria-live="polite">Page {page}{entry?.count ? ` of ${entry.count}` : ''}</span><button type="button" className="button secondary" disabled={!ready || page >= entry.count} onClick={() => setPage(value => value + 1)}>Next page</button></div>
    {ready ? <img className="source-pdf-page" src={entry.objectUrl} alt={`${source.title}, page ${page}`} /> : failed ? <p role="alert" className="error-message">This page could not be displayed. Open the original PDF using the link above.</p> : <p role="status" className="pdf-loading">Loading PDF page…</p>}
  </div>
}
