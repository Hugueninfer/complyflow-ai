import { fireEvent, screen, waitFor, within } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { json } from '../../test/server'
import { openWorkspace, supplier, pdfDocument } from '../../test/workspace'

describe('Supplier workspace', () => {
  it('renders empty state, validates a new supplier and reflects the saved server record', async () => {
    const records: typeof supplier[] = []
    await openWorkspace('/fornecedores', (_path, init) => {
      if (init.method === 'POST') {
        expect(JSON.parse(String(init.body))).toEqual({ name: 'NovaGuard Facilities', tax_id: null, risk_level: 'medium' })
        records.push(supplier)
        return json({ data: supplier }, 201)
      }
      return json({ data: records })
    })
    expect(await screen.findByText(/nenhum fornecedor cadastrado/i)).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: /novo fornecedor/i }))
    const form = screen.getByRole('form', { name: /cadastro do fornecedor/i })
    await fireEvent.submit(form)
    expect(screen.getByLabelText('Razão social')).toHaveAttribute('aria-invalid', 'true')
    await fireEvent.update(screen.getByLabelText('Razão social'), 'NovaGuard Facilities')
    await fireEvent.submit(form)
    expect(await screen.findByRole('link', { name: 'NovaGuard Facilities' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent(/cadastrado/i)
  })

  it('shows loading and retries a failed list then filters by name and risk', async () => {
    let release!: (r: Response) => void
    let first = true
    await openWorkspace('/fornecedores', () => first ? new Promise(resolve => { release = resolve }) : json({ data: [supplier] }))
    expect(screen.getByLabelText('Carregando fornecedores')).toBeVisible()
    release(json({}, 500)); first = false
    await screen.findByRole('alert')
    await fireEvent.click(screen.getByRole('button', { name: /tentar novamente/i }))
    await screen.findByRole('link', { name: 'NovaGuard Facilities' })
    await fireEvent.update(screen.getByLabelText('Buscar fornecedores'), 'missing')
    expect(screen.getByText(/nenhum resultado/i)).toBeVisible()
    await fireEvent.click(screen.getByRole('button', { name: /limpar filtros/i }))
    expect(screen.getByRole('link', { name: 'NovaGuard Facilities' })).toBeVisible()
    await fireEvent.update(screen.getByLabelText('Risco'), 'low')
    expect(screen.getByText(/nenhum resultado/i)).toBeVisible()
  })

  it('hides create and edit actions without server permissions', async () => {
    const router = await openWorkspace('/fornecedores', (path) => json({ data: path.endsWith('/suppliers') ? [supplier] : supplier }), ['supplier.view'])
    await screen.findByRole('link', { name: 'NovaGuard Facilities' })
    expect(screen.queryByRole('button', { name: /novo fornecedor/i })).toBeNull()
    await router.push(`/fornecedores/${supplier.id}`)
    await screen.findByRole('heading', { name: supplier.name })
    expect(screen.queryByRole('button', { name: /editar cadastro/i })).toBeNull()
    expect(screen.queryByRole('link', { name: /enviar documentos/i })).toBeNull()
  })

  it('opens metadata, handles field errors, edits and requires explicit delete confirmation', async () => {
    let updated = false
    let conflict = true
    const router = await openWorkspace(`/fornecedores/${supplier.id}`, (path, init) => {
      if (init.method === 'DELETE') return new Response(null, { status: 204 })
      if (init.method === 'PUT') {
        if (conflict) return json({ errors: { tax_id: ['Already taken.'] } }, 422)
        updated = true
        return json({ data: { ...supplier, name: 'Novo nome' } })
      }
      if (path.includes('/documents')) return json({ data: [pdfDocument], meta: { current_page: 1, last_page: 1, total: 1 } })
      return json({ data: path.endsWith('/suppliers') ? [] : supplier })
    })
    expect(await screen.findByText('generated.pdf')).toBeVisible()
    expect(within(screen.getByRole('navigation', { name: 'Principal' })).getByRole('link', { name: 'Fornecedores' })).toHaveClass('active')
    await fireEvent.click(screen.getByRole('button', { name: /editar cadastro/i }))
    await fireEvent.update(screen.getByLabelText('Razão social'), 'Novo nome')
    await fireEvent.submit(screen.getByRole('form', { name: /cadastro do fornecedor/i }))
    await waitFor(() => expect(screen.getByLabelText('CNPJ / identificação fiscal')).toHaveAttribute('aria-invalid', 'true'))
    conflict = false
    await fireEvent.submit(screen.getByRole('form', { name: /cadastro do fornecedor/i }))
    expect(await screen.findByRole('heading', { name: 'Novo nome' })).toBeVisible()
    expect(updated).toBe(true)
    await fireEvent.click(screen.getByRole('button', { name: 'Excluir fornecedor' }))
    const confirm = screen.getByRole('alertdialog')
    await fireEvent.click(within(confirm).getByRole('button', { name: 'Confirmar exclusão' }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/fornecedores'))
  })
})
