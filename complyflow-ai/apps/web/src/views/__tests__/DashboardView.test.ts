import { fireEvent, screen, within } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { openWorkspace } from '../../test/workspace'
import { json } from '../../test/server'
import { portfolioPermissions } from '../../test/portfolio'
describe('Dashboard', () => {
  it('renders actual metrics with assistive meaning and useful destinations', async () => {
    await openWorkspace('/', () => json({ data: { suppliers_analyzed: 7, requirements_met: 23, pending_requirements: 4, analyses_awaiting_review: 2 } }), portfolioPermissions)
    const metric = await screen.findByRole('region', { name: 'Fornecedores analisados' })
    expect(within(metric).getByText('7')).toBeVisible()
    expect(screen.getByRole('region', { name: 'Requisitos atendidos' })).toHaveTextContent('23')
    expect(screen.getByRole('region', { name: 'Pendências' })).toHaveTextContent('4')
    expect(screen.getByRole('region', { name: 'Aguardando revisão' })).toHaveTextContent('2')
    expect(screen.getByText(/status efetivo.*sugestões/i)).toBeVisible()
    expect(screen.getByRole('link', { name: 'Explorar fornecedores' })).toHaveAttribute('href', '/fornecedores')
  })
  it('shows safe permission errors, supports retry and zero-state', async () => {
    let denied = true
    await openWorkspace('/', () => denied ? json({ message: 'SECRET' }, 403) : json({ data: { suppliers_analyzed: 0, requirements_met: 0, pending_requirements: 0, analyses_awaiting_review: 0 } }), portfolioPermissions)
    expect(await screen.findByRole('alert')).toHaveTextContent(/permissão/i)
    expect(screen.queryByText('SECRET')).toBeNull(); denied = false
    await fireEvent.click(screen.getByRole('button', { name: /tentar novamente/i }))
    expect(await screen.findByText(/nenhuma análise atual concluída/i)).toBeVisible()
  })
})
