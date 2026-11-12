import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { api, mutate } from '../lib/api'
import { Feedback, useAction, useResource } from './WorkspaceParts'

export default function ReviewPanel({ brief, version, latestVersionId, onChanged }) {
  const { user } = useAuth()
  const resource = useResource(`/briefs/${brief.id}/review-workflow`)
  const action = useAction()
  const [comment, setComment] = useState('')
  const [format, setFormat] = useState('markdown')
  const submissions = resource.data?.submissions || []
  const current = submissions.find(item => item.status === 'SUBMITTED' && item.version_id === version?.id)
  const currentIsLatest = version?.id && version.id === latestVersionId
  const assignedToMe = brief.instructions.reviewer_id === user.id
  const mayReview = current && currentIsLatest && user.id !== version?.author_id &&
    ((user.role === 'REVIEWER' && assignedToMe) || user.role === 'ADMIN')
  const maySubmit = ['ADMIN', 'WRITER'].includes(user.role) && version && currentIsLatest &&
    brief.status !== 'IN_PROGRESS' && brief.status !== 'IN_REVIEW' && brief.instructions.reviewer_id &&
    !version.quality_findings?.some(item => item.severity === 'HARD')
  const mayEdit = ['ADMIN', 'WRITER'].includes(user.role) && version && currentIsLatest &&
    !['IN_PROGRESS', 'IN_REVIEW'].includes(brief.status)

  async function submit() {
    const result = await action.run(() => mutate('post', `/briefs/${brief.id}/submit-review`, { version_id: version.id }), 'Version sent to the assigned reviewer.')
    if (result) { resource.reload(); onChanged() }
  }
  async function decide(decision) {
    const result = await action.run(() => mutate('post', `/reviews/${current.id}/decision`, { decision, comment }), decision === 'APPROVED' ? 'Version approved.' : 'Changes requested.')
    if (result) { setComment(''); resource.reload(); onChanged() }
  }
  async function exportVersion() {
    const result = await action.run(() => api.get(`/versions/${version.id}/export`, { params: { format }, responseType: 'blob' }), 'Approved version downloaded.')
    if (result) {
      const disposition = result.headers['content-disposition'] || ''
      const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || `approved-content.${format === 'html' ? 'html' : 'md'}`
      const url = URL.createObjectURL(result.data)
      const link = document.createElement('a')
      link.href = url; link.download = filename; link.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    }
  }

  return <section className="editor-panel review-panel">
    <div className="section-heading"><h3>Review and approval</h3><span className="tag">{brief.status.replaceAll('_', ' ')}</span></div>
    <Feedback action={action} resource={resource} />
    {mayEdit && <p className="helper-text">Save edits as a new version. Earlier versions and their review history stay unchanged; edited passages lose their old citations.</p>}
    {maySubmit && <div className="button-row"><button className="button primary" type="button" disabled={action.busy} onClick={submit}>Submit version {version.version_no} for review</button><span className="helper-text">Assigned reviewer: {resource.data?.assigned_reviewer?.name || 'Loading reviewer details'}</span></div>}
    {brief.status === 'APPROVED' && currentIsLatest && <div className="button-row"><label>Export format<select value={format} onChange={event => setFormat(event.target.value)}><option value="markdown">Markdown</option><option value="html">HTML</option></select></label><button type="button" className="button primary" disabled={action.busy} onClick={exportVersion}>Download approved version</button></div>}
    {mayReview && <div className="review-decision"><h4>Review version {version.version_no}</h4><p className="helper-text">Review the draft and citations above. Approval applies only to this exact version.</p><label>Reviewer note<textarea value={comment} onChange={event => setComment(event.target.value)} maxLength="2000" rows="3" placeholder="Required when requesting changes." /></label><div className="button-row"><button type="button" className="button primary" disabled={action.busy} onClick={() => decide('APPROVED')}>Approve version</button><button type="button" className="button secondary" disabled={action.busy || comment.trim().length < 5} onClick={() => decide('CHANGES_REQUESTED')}>Request changes</button></div></div>}
    {!brief.instructions.reviewer_id && mayEdit && <p className="archive-notice">Assign a reviewer in the brief settings before submitting for approval.</p>}
    {submissions.length > 0 && <details className="review-history"><summary>Review history ({submissions.length})</summary>{submissions.map(item => <article className="review-history-item" key={item.id}><strong>Version {item.version?.version_no} · {item.status.replaceAll('_', ' ')}</strong><p>Submitted by {item.submitter?.name} to {item.reviewer?.name} · {new Date(item.submitted_at).toLocaleString()}</p>{item.decided_at && <p>Decision by {item.reviewer?.name} · {new Date(item.decided_at).toLocaleString()}</p>}{item.decision_comment && <blockquote>{item.decision_comment}</blockquote>}</article>)}</details>}
  </section>
}
