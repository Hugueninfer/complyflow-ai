import { fireEvent, screen, waitFor } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { json } from '../../test/server'
import { openWorkspace, requirementSet } from '../../test/workspace'

describe('Requirement lifecycle', () => {
  it('creates a validated draft with evaluation criteria and announces server field errors', async () => {
    let conflict = true
    await openWorkspace('/requisitos', (_path, init) => {
      if (init.method === 'POST') {
        if (conflict) return json({ errors: { 'requirements.0.code': ['Invalid'] } }, 422)
        expect(JSON.parse(String(init.body))).toMatchObject({ name: 'Homologação', requirements: [{ code: 'FISC-01', title: 'Certidão', category: 'Fiscal', evaluation_text: 'Validade vigente', weight: 1, position: 0, is_required: true }] })
        return json({ data: requirementSet }, 201)
      }
      return json({ data: [] })
    })
    expect(await screen.findByText(/nenhum conjunto de requisitos/i)).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: 'Novo conjunto' }))
    const form = screen.getByRole('form', { name: 'Editor de requisitos' })
    await fireEvent.submit(form)
    expect(screen.getByLabelText('Nome do conjunto')).toHaveAttribute('aria-invalid', 'true')
    await fireEvent.update(screen.getByLabelText('Nome do conjunto'), 'Homologação')
    await fireEvent.update(screen.getByLabelText('Código 1'), 'FISC-01')
    await fireEvent.update(screen.getByLabelText('Título 1'), 'Certidão')
    await fireEvent.update(screen.getByLabelText('Categoria 1'), 'Fiscal')
    await fireEvent.update(screen.getByLabelText('Critério de avaliação 1'), 'Validade vigente')
    await fireEvent.submit(form)
    await waitFor(() => expect(screen.getByLabelText('Código 1')).toHaveAttribute('aria-invalid', 'true'))
    conflict = false
    await fireEvent.submit(form)
    expect(await screen.findByRole('status')).toHaveTextContent(/salvo/i)
    expect(screen.getByRole('heading', { name: 'Homologação' })).toBeVisible()
  })

  it('edits a draft, explicitly publishes an immutable version and creates a new draft version', async () => {
    let current = { ...requirementSet }
    await openWorkspace('/requisitos', (path, init) => {
      if (init.method === 'PUT') { current = { ...current, name: 'Atualizado' }; return json({ data: current }) }
      if (path.endsWith('/publish')) { current = { ...current, status: 'published' }; return json({ data: current }) }
      if (path.endsWith('/versions')) return json({ data: { ...current, id: 'set-2', version: 2, status: 'draft' } }, 201)
      return json({ data: [current] })
    })
    await fireEvent.click(await screen.findByRole('button', { name: 'Editar rascunho' }))
    await fireEvent.update(screen.getByLabelText('Nome do conjunto'), 'Atualizado')
    await fireEvent.submit(screen.getByRole('form', { name: 'Editor de requisitos' }))
    await screen.findByRole('heading', { name: 'Atualizado' })
    const publishButton = screen.getByRole('button', { name: 'Publicar versão' })
    publishButton.focus()
    await fireEvent.click(publishButton)
    await fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    expect(screen.getByRole('button', { name: 'Publicar versão' })).toHaveFocus()
    await fireEvent.click(screen.getByRole('button', { name: 'Publicar versão' }))
    expect(screen.getByText(/após publicar.*imutável/i)).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: 'Confirmar publicação' }))
    await screen.findByText('Publicada')
    expect(screen.queryByRole('button', { name: 'Editar rascunho' })).toBeNull()
    await fireEvent.click(screen.getByRole('button', { name: 'Criar nova versão' }))
    expect(await screen.findByRole('form', { name: 'Editor de requisitos' })).toBeVisible()
    expect(screen.getByText('Versão 2 · Rascunho')).toBeVisible()
  })

  it('retries a failed load and hides mutations for read-only users', async () => {
    let first = true
    await openWorkspace('/requisitos', () => { if (first) { first = false; return json({}, 500) }; return json({ data: [requirementSet] }) }, ['requirement.view'])
    await screen.findByRole('alert')
    await fireEvent.click(screen.getByRole('button', { name: /tentar novamente/i }))
    expect(await screen.findByRole('heading', { name: 'Homologação' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Novo conjunto' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Editar rascunho' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Publicar versão' })).toBeNull()
  })
})
