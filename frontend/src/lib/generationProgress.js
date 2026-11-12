export function runLabel(run) {
  if (run.error_code === 'MISSING_EVIDENCE' && run.status === 'FAILED') return 'Needs references'
  if (run.error_code === 'QUALITY_FAILED' && run.status === 'FAILED') return 'Needs quality fixes'
  return { QUEUED: 'Queued', RUNNING: 'In progress', SUCCEEDED: 'Draft saved', FAILED: 'Run failed', CANCELLED: 'Cancelled' }[run.status] || run.status
}

export function generationStages(run) {
  return ['PLAN', 'RESEARCH', 'WRITE', 'QUALITY'].map(type => {
    const attempts = (run.steps || []).filter(step => step.step_type === type || (type === 'WRITE' && step.step_type === 'REVISE'))
      .sort((a, b) => a.pass_no - b.pass_no || a.attempt_no - b.attempt_no)
    const latest = attempts.at(-1)
    const needsReferences = type === 'RESEARCH' && run.error_code === 'MISSING_EVIDENCE'
    const label = type === 'QUALITY' && run.error_code === 'QUALITY_FAILED' ? 'Needs fixes' : needsReferences ? 'Needs references' : {
      SUCCEEDED: 'Completed', REUSED: 'Reused', FAILED: 'Failed', RUNNING: 'In progress', PENDING: 'Not started', SKIPPED: 'Not started',
    }[latest?.status || 'PENDING']
    return { type, latest, earlier: attempts.slice(0, -1), label }
  })
}
