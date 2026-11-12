import { useState } from 'react'
import { mutate } from '../lib/api'
import { Feedback, useAction } from './WorkspaceParts'

export default function DraftEditor({ briefId, version, onSaved, onCancel }) {
  const [title, setTitle] = useState(version.title)
  const [blocks, setBlocks] = useState(version.blocks.map(({ id, kind, text }) => ({ id, kind, text })))
  const [metadata, setMetadata] = useState({ ...version.format_metadata })
  const action = useAction()
  async function save(event) {
    event.preventDefault()
    const result = await action.run(() => mutate('post', `/briefs/${briefId}/versions`, {
      latest_version_id: version.id, title, blocks, format_metadata: metadata,
    }), 'A new manual version was saved.')
    if (result) onSaved(result.data.version)
  }
  function updateBlock(id, field, value) {
    setBlocks(current => current.map(block => block.id === id ? { ...block, [field]: value } : block))
  }
  return <form className="draft-editor" onSubmit={save}>
    <div className="section-heading"><h3>Edit version {version.version_no}</h3><button className="text-button" type="button" onClick={onCancel}>Cancel</button></div>
    <Feedback action={action} />
    <label>Title<input value={title} maxLength="200" required onChange={event => setTitle(event.target.value)} /></label>
    {blocks.map((block, index) => <fieldset className="draft-editor-block" key={block.id}><legend>Section {index + 1}</legend>
      <label>Format<select value={block.kind} onChange={event => updateBlock(block.id, 'kind', event.target.value)}><option value="HEADING">Heading</option><option value="PARAGRAPH">Paragraph</option><option value="BULLET">Bullet</option></select></label>
      <label>Content<textarea value={block.text} required maxLength="6000" rows="5" onChange={event => updateBlock(block.id, 'text', event.target.value)} /></label>
      <p className="helper-text">Changing this section removes its earlier citations. Unedited sections keep theirs.</p>
    </fieldset>)}
    {['email_subject', 'preview_text', 'seo_title', 'meta_description'].map(field => <label key={field}>{field.replaceAll('_', ' ')}<input maxLength="500" value={metadata[field] || ''} onChange={event => setMetadata(current => ({ ...current, [field]: event.target.value }))} /></label>)}
    <div className="button-row"><button className="button primary" disabled={action.busy}>{action.busy ? 'Saving…' : 'Save as new version'}</button><span className="helper-text">Version history is preserved.</span></div>
  </form>
}
