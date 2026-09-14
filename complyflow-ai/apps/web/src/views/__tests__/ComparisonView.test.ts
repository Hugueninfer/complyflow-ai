import { fireEvent, screen } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { openWorkspace, supplier } from '../../test/workspace'
import { json } from '../../test/server'
import { comparison, portfolioPermissions, published, secondSupplier } from '../../test/portfolio'
const query = `left=${supplier.id}&right=supplier-2&requirement_set=set-1`
describe('Comparison', () => {
  it('loads exact deep query, aligns missing results and separates AI from human history', async () => {
    const requests: string[] = []
    await openWorkspace(`/comparacoes?${query}`, path => {
      requests.push(path)
      if (path === '/api/v1/suppliers') return json({ data: [supplier, secondSupplier] })
      if (path === '/api/v1/requirement-sets') return json({ data: [published] })
      return json({ data: comparison })
    }, portfolioPermissions)
    expect(await screen.findByText('Regularidade fiscal')).toBeVisible()
    expect(requests).toContain(`/api/v1/comparisons?${query}`)
    expect(screen.getByLabelText('Fornecedor à esquerda')).toHaveValue(supplier.id)
    expect(screen.getByText('Certidão localizada pela IA.')).toBeVisible()
    expect(screen.getByText('Validade limitada.')).toBeVisible()
    expect(screen.getByText('Certidão válida.')).toBeVisible()
    expect(screen.getByText(/versão histórica do checklist/i)).toBeVisible()
    expect(screen.getByText(/análise histórica do fornecedor/i)).toBeVisible()
    expect(screen.getByText('Sem achado nesta versão.')).toBeVisible()
    expect(screen.getByRole('link', { name: /abrir matriz.*NovaGuard/i })).toHaveAttribute('href', '/analises/run-1/matriz')
    expect(screen.queryByText(/vencedor|ranking|recomendado para aprovação/i)).toBeNull()
  })
  it('requires distinct suppliers and a real published checklist before comparing', async () => {
    let comparisons = 0
    await openWorkspace('/comparacoes', path => {
      if (path === '/api/v1/suppliers') return json({ data: [supplier, secondSupplier] })
      if (path === '/api/v1/requirement-sets') return json({ data: [published, { ...published, id: 'draft', status: 'draft', name: 'Rascunho' }] })
      comparisons++; return json({ data: { ...comparison, rows: [] } })
    }, portfolioPermissions)
    await screen.findByLabelText('Fornecedor à esquerda')
    expect(screen.queryByRole('option', { name: /Rascunho/ })).toBeNull()
    expect(screen.getByRole('button', { name: 'Comparar fornecedores' })).toBeDisabled()
    await fireEvent.update(screen.getByLabelText('Fornecedor à esquerda'), supplier.id)
    await fireEvent.update(screen.getByLabelText('Fornecedor à direita'), supplier.id)
    await fireEvent.update(screen.getByLabelText('Versão do checklist'), 'set-1')
    expect(screen.getByRole('button', { name: 'Comparar fornecedores' })).toBeDisabled(); expect(comparisons).toBe(0)
    await fireEvent.update(screen.getByLabelText('Fornecedor à direita'), secondSupplier.id)
    await fireEvent.click(screen.getByRole('button', { name: 'Comparar fornecedores' }))
    expect(await screen.findByText(/nenhum requisito nesta versão/i)).toBeVisible()
    expect(comparisons).toBe(1)
  })
  it('shows sanitized comparison errors', async () => {
    await openWorkspace(`/comparacoes?${query}`, path => path.includes('/comparisons?') ? json({ message: 'SECRET' }, 422) : json({ data: path.endsWith('suppliers') ? [supplier, secondSupplier] : [published] }), portfolioPermissions)
    expect(await screen.findByRole('alert')).toHaveTextContent(/verifique os dados/i)
    expect(screen.queryByText('SECRET')).toBeNull()
  })
})
