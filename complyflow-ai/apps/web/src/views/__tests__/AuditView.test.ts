import { fireEvent, screen } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { openWorkspace } from '../../test/workspace'
import { json } from '../../test/server'
import { auditEvent, portfolioPermissions } from '../../test/portfolio'
describe('Audit', () => {
  it('renders legacy records with nullable metadata without claiming verification', async () => {
    await openWorkspace('/auditoria', () => json({ data: [{ ...auditEvent, metadata: null, organization_public_id: null, actor_public_id: null }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, integrity: { status: 'unverifiable', scope: 'page' } } }), portfolioPermissions)
    expect(await screen.findByText(/verificação indisponível para registros legados/i)).toBeVisible()
    expect(screen.getByText('event-1')).toBeVisible()
    expect(screen.queryByRole('link', { name: /consultar análise relacionada/i })).toBeNull()
  })
  it('paginates real events and marks technical verification and broken chain', async () => {
    await openWorkspace('/auditoria', path => {
      const second = new URL(path, 'http://localhost').searchParams.get('page') === '2'
      return json({ data: [{ ...auditEvent, id: second ? 'event-2' : 'event-1' }], meta: { current_page: second ? 2 : 1, last_page: 2, per_page: 25, total: 26, integrity: { status: second ? 'broken' : 'verified', scope: 'page' } } })
    }, portfolioPermissions)
    expect(await screen.findByText(/hashes e encadeamento desta página verificados/i)).toBeVisible()
    expect(screen.getByText(/não é certificação jurídica/i)).toBeVisible()
    expect(screen.getByText('Decisão humana registrada')).toBeVisible()
    await fireEvent.click(screen.getByText('Detalhes técnicos'))
    expect(screen.getByText('a'.repeat(64))).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: 'Próxima página' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/quebra.*detectada/i)
    expect(screen.getByText('event-2')).toBeVisible()
  })
  it('does not fetch for unauthorized users', async () => {
    let calls = 0
    const router = await openWorkspace('/auditoria', () => { calls++; return json({}) }, ['supplier.view'])
    expect(router.currentRoute.value.path).toBe('/sem-permissao'); expect(calls).toBe(0)
  })
  it('handles an empty audit ledger', async () => {
    await openWorkspace('/auditoria', () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, integrity: { status: 'empty', scope: 'page' } } }), portfolioPermissions)
    expect(await screen.findByText(/nenhum evento registrado/i)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Próxima página' })).toBeDisabled()
  })
})
