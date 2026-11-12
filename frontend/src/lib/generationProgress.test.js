import test from 'node:test'
import assert from 'node:assert/strict'
import { generationStages, runLabel } from './generationProgress.js'

test('a recovered research attempt is completed and its earlier failure is history', () => {
  const stages = generationStages({ status: 'SUCCEEDED', steps: [
    { step_type: 'RESEARCH', pass_no: 0, attempt_no: 1, status: 'SUCCEEDED' },
    { step_type: 'RESEARCH', pass_no: 0, attempt_no: 0, status: 'FAILED' },
  ] })
  assert.equal(stages[1].label, 'Completed')
  assert.equal(stages[1].earlier[0].status, 'FAILED')
  assert.deepEqual(stages.map(stage => stage.type), ['PLAN', 'RESEARCH', 'WRITE', 'QUALITY'])
})

test('insufficient evidence asks for references and does not call undispatched stages failed', () => {
  const run = { status: 'FAILED', error_code: 'MISSING_EVIDENCE', steps: [
    { step_type: 'PLAN', pass_no: 0, attempt_no: 0, status: 'SUCCEEDED' },
    { step_type: 'RESEARCH', pass_no: 0, attempt_no: 1, status: 'SUCCEEDED' },
    { step_type: 'WRITE', pass_no: 0, attempt_no: 0, status: 'SKIPPED' },
    { step_type: 'QUALITY', pass_no: 0, attempt_no: 0, status: 'SKIPPED' },
  ] }
  assert.equal(runLabel(run), 'Needs references')
  assert.deepEqual(generationStages(run).map(stage => stage.label), ['Completed', 'Needs references', 'Not started', 'Not started'])
})

test('the current revision result takes precedence over previous successful passes', () => {
  const stages = generationStages({ status: 'FAILED', steps: [
    { step_type: 'WRITE', pass_no: 1, attempt_no: 0, status: 'FAILED' },
    { step_type: 'WRITE', pass_no: 0, attempt_no: 0, status: 'SUCCEEDED' },
  ] })
  assert.equal(stages[2].label, 'Failed')
  assert.equal(stages[2].latest.pass_no, 1)
})

test('quality failures remain visible and targeted edits belong to the writing stage', () => {
  const run = { status: 'FAILED', error_code: 'QUALITY_FAILED', steps: [
    { step_type: 'WRITE', pass_no: 0, attempt_no: 0, status: 'SUCCEEDED' },
    { step_type: 'REVISE', pass_no: 1, attempt_no: 0, status: 'SUCCEEDED' },
    { step_type: 'QUALITY', pass_no: 1, attempt_no: 0, status: 'SUCCEEDED' },
  ] }
  assert.equal(runLabel(run), 'Needs quality fixes')
  assert.equal(generationStages(run)[2].latest.step_type, 'REVISE')
  assert.equal(generationStages(run)[3].label, 'Needs fixes')
})
