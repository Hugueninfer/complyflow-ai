import { fireEvent, screen, within } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { json } from '../../test/server'
import { openWorkspace } from '../../test/workspace'
import { finding, humanReview } from '../../test/analysis'

describe('Compliance matrix', () => {
  it('restores focus to a filter when saving removes the evidence trigger from pending findings', async () => {
    let saved = false
    await openWorkspace('/analises/run-1/matriz', (_path, init) => {
      if (init.method === 'POST') { saved = true; return json({ data: humanReview }, 201) }
      return json({ data: [{ ...finding, latest_review: saved ? humanReview : null }] })
    }, ['analysis.view', 'finding.review'])
    await screen.findByText('Regularidade fiscal')
    await fireEvent.click(screen.getByLabelText('Apenas aguardando revisão'))
    await fireEvent.click(screen.getByRole('button', { name: /inspecionar FISC-01/i }))
    await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Inspeção concluída.')
    await fireEvent.click(screen.getByLabelText(/confirmo que inspecionei/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' }); await flushPromises()
    expect(screen.queryByRole('button', { name: /inspecionar FISC-01/i })).toBeNull()
    expect(screen.getByLabelText('Buscar requisito ou evidência')).toHaveFocus()
  })
  it('keeps a conflicting draft readable and copyable after refreshing a final-decision lock', async () => {
    let locked = false, posts = 0
    await openWorkspace('/analises/run-1/matriz', (_path, init) => {
      if (init.method === 'POST') { posts++; locked = true; return json({}, 409) }
      return json({ data: [{ ...finding, review_locked: locked }] })
    }, ['analysis.view', 'finding.review'])
    await fireEvent.click(await screen.findByRole('button', { name: /inspecionar FISC-01/i }))
    await fireEvent.update(screen.getByLabelText('Status revisado'), 'partial')
    await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Escopo limitado após inspeção.')
    await fireEvent.update(screen.getByLabelText('Observação opcional'), 'Copiar para acompanhamento.')
    await fireEvent.click(screen.getByLabelText(/confirmo que inspecionei/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    expect(screen.getByText(/revisões bloqueadas/i)).toBeVisible()
    expect(screen.getByRole('alert')).toHaveTextContent(/decisão final/i)
    expect(screen.getByLabelText('Status revisado')).toHaveValue('partial')
    const draft = screen.getByLabelText('Justificativa humana')
    expect(draft).toHaveValue('Escopo limitado após inspeção.')
    expect(draft).toHaveAttribute('readonly'); expect(draft).not.toBeDisabled()
    draft.focus(); expect(draft).toHaveFocus()
    expect(screen.getByLabelText('Observação opcional')).toHaveValue('Copiar para acompanhamento.')
    expect(screen.getByRole('button', { name: 'Salvar revisão' })).toBeDisabled()
    await fireEvent.submit(screen.getByRole('form', { name: 'Revisão humana do achado' })); await flushPromises()
    expect(posts).toBe(1)
  })
  it('filters AI statuses and text, and shows missing evidence without inventing citations', async () => {
    const missing = { ...finding, id: 'finding-2', status: 'missing', search_summary: 'Busca em todas as páginas.', citations: [], requirement: { ...finding.requirement, code: 'PRIV-01', title: 'Privacidade', category: 'LGPD' } }
    await openWorkspace('/analises/run-1/matriz', () => json({ data: [finding, missing] }), ['analysis.view'])
    await screen.findByText('Regularidade fiscal')
    await fireEvent.update(screen.getByLabelText('Status da IA'), 'missing')
    expect(screen.queryByText('Regularidade fiscal')).toBeNull()
    await fireEvent.click(screen.getByRole('button', { name: /inspecionar PRIV-01/i }))
    expect(screen.getByRole('dialog')).toHaveTextContent('Busca em todas as páginas.')
    expect(screen.queryByRole('button', { name: 'Salvar revisão' })).toBeNull()
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' }); await flushPromises()
    expect(screen.getByRole('button', { name: /inspecionar PRIV-01/i })).toHaveFocus()
    await fireEvent.update(screen.getByLabelText('Buscar requisito ou evidência'), 'inexistente')
    expect(screen.getByText(/nenhum requisito corresponde/i)).toBeVisible()
  })
  it('opens accessible evidence, saves human review separately and preserves the AI suggestion', async () => {
    let saved = false
    await openWorkspace('/analises/run-1/matriz', (_path, init) => { if (init.method === 'POST') { saved = true; return json({ data: humanReview }, 201) }; return json({ data: [{ ...finding, latest_review: saved ? humanReview : null }] }) }, ['analysis.view', 'finding.review'])
    const trigger = await screen.findByRole('button', { name: /inspecionar FISC-01/i }); trigger.focus(); await fireEvent.click(trigger)
    const dialog = screen.getByRole('dialog'); expect(within(dialog).getByRole('button', { name: 'Fechar evidência' })).toHaveFocus()
    expect(dialog).toHaveTextContent('document-1'); expect(dialog).toHaveTextContent('Página 2'); expect(dialog).toHaveTextContent('14–30'); expect(dialog).toHaveTextContent('Certidão válida.')
    await fireEvent.update(screen.getByLabelText('Status revisado'), 'partial')
    await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Validade limitada.')
    await fireEvent.click(screen.getByLabelText(/confirmo que inspecionei/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    expect(screen.getByRole('status')).toHaveTextContent(/revisão registrada/i)
    expect(dialog).toHaveTextContent('Certidão localizada pela IA.')
    expect(dialog).toHaveTextContent('<script>alert(1)</script>')
    expect(dialog.querySelector('script')).toBeNull()
  })
  it('recovers load errors and distinguishes an empty matrix', async () => {
    let fail = true
    await openWorkspace('/analises/run-1/matriz', () => fail ? json({}, 500) : json({ data: [] }), ['analysis.view'])
    await screen.findByRole('alert'); fail = false
    await fireEvent.click(screen.getByRole('button', { name: /tentar novamente/i }))
    expect(await screen.findByText(/nenhum achado disponível/i)).toBeVisible()
  })
})
