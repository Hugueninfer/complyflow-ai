import { fireEvent, screen } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { openWorkspace } from '../../test/workspace'
import { json } from '../../test/server'
import { finding, humanReview } from '../../test/analysis'
import { decision, decisionContext, portfolioPermissions } from '../../test/portfolio'
describe('Human decision journey', () => {
  it('reuses the idempotency key after an uncertain response and hides decision controls for analysts', async () => {
    const keys: string[] = []
    await openWorkspace('/analises/run-1/matriz', (_path, init) => {
      if (init.method === 'POST') { keys.push(new Headers(init.headers).get('Idempotency-Key')!); return keys.length === 1 ? json({}, 500) : json({ data: decision }) }
      return json({ data: [finding], meta: { decision_context: decisionContext } })
    }, portfolioPermissions)
    await screen.findByLabelText('Decisão final humana')
    await fireEvent.update(screen.getByLabelText('Decisão final humana'), 'conditional')
    await fireEvent.update(screen.getByLabelText('Justificativa da decisão'), decision.reason)
    await fireEvent.click(screen.getByLabelText(/assumo a responsabilidade/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Registrar decisão humana' })); await flushPromises()
    expect(screen.getByRole('alert')).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: 'Registrar decisão humana' })); await flushPromises()
    expect(keys).toHaveLength(2); expect(keys[0]).toBe(keys[1])
    expect(screen.getByText(/decisão humana registrada.*imutável/i)).toBeVisible()
  })
  it('does not expose decision controls to a user without permission even if context is supplied', async () => {
    await openWorkspace('/analises/run-1/matriz', () => json({ data: [finding], meta: { decision_context: decisionContext } }), ['analysis.view'])
    await screen.findByText('Regularidade fiscal')
    expect(screen.queryByRole('region', { name: 'Decisão final do fornecedor' })).toBeNull()
  })
  it('preserves a conflicting draft even if refreshing the matrix fails', async () => {
    let conflict = false
    await openWorkspace('/analises/run-1/matriz', (_path, init) => {
      if (init.method === 'POST') { conflict = true; return json({}, 409) }
      return conflict ? json({}, 500) : json({ data: [finding], meta: { decision_context: decisionContext } })
    }, portfolioPermissions)
    await screen.findByLabelText('Decisão final humana')
    await fireEvent.update(screen.getByLabelText('Decisão final humana'), 'conditional')
    await fireEvent.update(screen.getByLabelText('Justificativa da decisão'), 'Preservar para inspeção.')
    await fireEvent.click(screen.getByLabelText(/assumo a responsabilidade/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Registrar decisão humana' })); await flushPromises()
    expect(screen.getByLabelText('Justificativa da decisão')).toHaveValue('Preservar para inspeção.')
    expect(screen.getByLabelText('Justificativa da decisão')).toHaveAttribute('readonly')
  })
  it('requires explicit decision and reason then persists an immutable human result with idempotency', async () => {
    let saved = false, payload: unknown, key = '', pathWritten = ''
    await openWorkspace('/analises/run-1/matriz', (path, init) => {
      if (init.method === 'POST') { payload = JSON.parse(String(init.body)); key = new Headers(init.headers).get('Idempotency-Key') ?? ''; pathWritten = path; saved = true; return json({ data: decision }, 201) }
      return json({ data: [{ ...finding, latest_review: humanReview }], meta: { decision_context: { ...decisionContext, decision: saved ? decision : null } } })
    }, portfolioPermissions)
    const button = await screen.findByRole('button', { name: 'Registrar decisão humana' })
    expect(button).toBeDisabled()
    await fireEvent.update(screen.getByLabelText('Decisão final humana'), 'conditional')
    await fireEvent.update(screen.getByLabelText('Justificativa da decisão'), decision.reason)
    await fireEvent.click(screen.getByLabelText(/assumo a responsabilidade/i))
    await fireEvent.click(button); await flushPromises()
    expect(payload).toEqual({ analysis_id: 'run-1', decision: 'conditional', reason: decision.reason })
    expect(pathWritten).toBe(`/api/v1/suppliers/${decisionContext.supplier_id}/decisions`); expect(key.length).toBeGreaterThan(8)
    expect(screen.getByText(/decisão humana registrada.*imutável/i)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Registrar decisão humana' })).toBeNull()
    expect(screen.getByRole('heading', { name: /decisão humana registrada.*imutável/i })).toHaveFocus()
    await fireEvent.click(screen.getByRole('button', { name: /inspecionar FISC-01/i }))
    expect(screen.getByText('Certidão localizada pela IA.')).toBeVisible()
  })
  it.each([{ required_pending: 1 }, { is_latest_for_supplier: false }, { is_current_checklist: false }])('prevents final submission with pending or historical context %j', async override => {
    await openWorkspace('/analises/run-1/matriz', () => json({ data: [finding], meta: { decision_context: { ...decisionContext, ...override } } }), portfolioPermissions)
    expect(await screen.findByText(/decisão indisponível/i)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Registrar decisão humana' })).toBeNull()
  })
  it.each([403, 409, 422])('shows safe %i errors and preserves the reason', async status => {
    await openWorkspace('/analises/run-1/matriz', (_path, init) => init.method === 'POST' ? json({ message: 'SECRET' }, status) : json({ data: [finding], meta: { decision_context: decisionContext } }), portfolioPermissions)
    await screen.findByLabelText('Decisão final humana')
    await fireEvent.update(screen.getByLabelText('Decisão final humana'), 'rejected')
    await fireEvent.update(screen.getByLabelText('Justificativa da decisão'), 'Rascunho humano.')
    await fireEvent.click(screen.getByLabelText(/assumo a responsabilidade/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Registrar decisão humana' })); await flushPromises()
    expect(screen.getByRole('alert')).toBeVisible(); expect(screen.queryByText('SECRET')).toBeNull()
    expect(screen.getByLabelText('Justificativa da decisão')).toHaveValue('Rascunho humano.')
    if (status === 409 || status === 403) expect(screen.getByRole('button', { name: 'Registrar decisão humana' })).toBeDisabled()
  })
})
