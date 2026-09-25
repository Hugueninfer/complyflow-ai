import { fireEvent, screen, waitFor, within } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { json } from '../../test/server'
import { openWorkspace, supplier, pdfDocument, requirementSet } from '../../test/workspace'

describe('Supplier workspace', () => {
  it.each(['completed', 'failed'])('allows a new run after a %s analysis', async status => {
    await openWorkspace(`/fornecedores/${supplier.id}`, path => {
      if (path.includes('/documents')) return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
      if (path.includes('/requirement-sets')) return json({ data: [] })
      return json({ data: { ...supplier, latest_analysis: { id: 'previous-run', status } } })
    }, ['supplier.view', 'analysis.view', 'analysis.run'])
    expect(await screen.findByRole('heading', { name: 'Iniciar nova análise' })).toBeVisible()
  })

  it.each(['pending', 'processing'])('directs the user to the existing %s run instead of creating another by accident', async status => {
    let selections = 0
    await openWorkspace(`/fornecedores/${supplier.id}`, path => {
      if (path.includes('/requirement-sets') || path.includes('/documents')) { selections++; return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) }
      return json({ data: { ...supplier, latest_analysis: { id: 'current-run', status } } })
    }, ['supplier.view', 'analysis.view', 'analysis.run'])
    expect(await screen.findByRole('link', { name: 'Acompanhar análise' })).toHaveAttribute('href', '/analises/current-run')
    expect(screen.queryByRole('heading', { name: 'Iniciar nova análise' })).not.toBeInTheDocument()
    expect(selections).toBe(0)
  })

  it('shows creation only with the server analysis.run permission', async () => {
    await openWorkspace(`/fornecedores/${supplier.id}`, () => json({ data: supplier }), ['supplier.view', 'analysis.view', 'finding.review'])
    await screen.findByRole('heading', { name: supplier.name })
    expect(screen.queryByRole('heading', { name: 'Iniciar nova análise' })).not.toBeInTheDocument()
  })

  it('returns an expired creation session to login without leaking selection data', async () => {
    const router = await openWorkspace(`/fornecedores/${supplier.id}`, (path, init) => {
      if (init.method === 'POST') return json({}, 401)
      if (path.includes('/requirement-sets')) return json({ data: [{ ...requirementSet, id: 'set', status: 'published' }] })
      if (path.includes('/documents')) return json({ data: [pdfDocument], meta: { current_page: 1, last_page: 1, total: 1 } })
      return json({ data: supplier })
    }, ['supplier.view', 'analysis.view', 'analysis.run'])
    await fireEvent.update(await screen.findByLabelText('Conjunto de requisitos'), 'set')
    await fireEvent.click(await screen.findByRole('checkbox'))
    await fireEvent.submit(screen.getByRole('form', { name: 'Iniciar análise documental' }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
    expect(screen.queryByRole('heading', { name: supplier.name, level: 1 })).not.toBeInTheDocument()
  })
  it('starts the first analysis with a published checklist, selected PDFs and an idempotency key', async () => {
    const published = { ...requirementSet, id: 'ed9909e1-8af8-4e27-a8d0-daa3bfc0dc23', status: 'published', published_at: '2026-09-13T12:00:00Z' }
    const ready = { ...pdfDocument, status: 'ready' }
    let posted = 0
    let key = ''
    const router = await openWorkspace(`/fornecedores/${supplier.id}`, (path, init) => {
      if (path === '/api/v1/requirement-sets') return json({ data: [published] })
      if (path.includes('/documents')) return json({ data: [ready], meta: { current_page: 1, last_page: 1, total: 1 } })
      if (init.method === 'POST' && path.endsWith('/analyses')) {
        posted++
        key = (init.headers as Headers).get('Idempotency-Key') || ''
        expect(JSON.parse(String(init.body))).toEqual({ requirement_set_id: published.id, document_ids: [ready.id] })
        return json({ data: { id: 'analysis-1', status: 'pending', attempts: 0, progress: 0 } }, 202)
      }
      return json({ data: supplier })
    }, [...new Set(['supplier.view', 'document.view', 'analysis.view', 'analysis.run', 'requirement.view'])])
    await screen.findByRole('heading', { name: /iniciar nova análise/i })
    await fireEvent.update(await screen.findByLabelText('Conjunto de requisitos'), published.id)
    await fireEvent.click(screen.getByLabelText(/generated\.pdf/i))
    await fireEvent.click(screen.getByRole('button', { name: /iniciar análise documental/i }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/analises/analysis-1'))
    expect(posted).toBe(1)
    expect(key).toMatch(/^analysis-ui:[A-Za-z0-9-]+$/)
  })

  it('reuses the idempotency key after an uncertain network failure and prevents double submit', async () => {
    const published = { ...requirementSet, id: 'ed9909e1-8af8-4e27-a8d0-daa3bfc0dc23', status: 'published', published_at: '2026-09-13T12:00:00Z' }
    const keys: string[] = []
    let release!: (response: Response) => void
    let attempt = 0
    await openWorkspace(`/fornecedores/${supplier.id}`, (path, init) => {
      if (path === '/api/v1/requirement-sets') return json({ data: [published] })
      if (path.includes('/documents')) return json({ data: [{ ...pdfDocument, status: 'uploaded' }], meta: { current_page: 1, last_page: 1, total: 1 } })
      if (init.method === 'POST' && path.endsWith('/analyses')) {
        keys.push((init.headers as Headers).get('Idempotency-Key') || '')
        attempt++
        if (attempt === 1) throw new TypeError('connection lost')
        return new Promise(resolve => { release = resolve })
      }
      return json({ data: supplier })
    }, ['supplier.view', 'document.view', 'analysis.view', 'analysis.run', 'requirement.view'])
    await screen.findByRole('heading', { name: /iniciar nova análise/i })
    await fireEvent.update(await screen.findByLabelText('Conjunto de requisitos'), published.id)
    await fireEvent.click(screen.getByLabelText(/generated\.pdf/i))
    const submit = screen.getByRole('button', { name: /iniciar análise documental/i })
    await fireEvent.click(submit)
    expect(await screen.findByRole('alert')).toHaveTextContent(/conectar/i)
    await fireEvent.click(submit)
    await fireEvent.click(submit)
    await waitFor(() => expect(keys).toHaveLength(2))
    expect(keys[1]).toBe(keys[0])
    expect(submit).toBeDisabled()
    release(json({ data: { id: 'analysis-2', status: 'pending', attempts: 0, progress: 0 } }, 202))
  })

  it('shows actionable prerequisites and hides analysis creation without permission', async () => {
    await openWorkspace(`/fornecedores/${supplier.id}`, (path) => path === '/api/v1/requirement-sets'
      ? json({ data: [] })
      : path.includes('/documents')
        ? json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
        : json({ data: supplier }), ['supplier.view', 'document.view', 'document.upload', 'analysis.view', 'analysis.run', 'requirement.view', 'requirement.create', 'requirement.publish'])
    expect(await screen.findByRole('link', { name: /publicar conjunto/i })).toHaveAttribute('href', '/requisitos')
    expect(screen.getByRole('link', { name: /enviar pdf/i })).toHaveAttribute('href', `/fornecedores/${supplier.id}/documentos`)
    expect(screen.getByRole('button', { name: /iniciar análise documental/i })).toBeDisabled()
  })

  it('labels an API ready document as ready for analysis', async () => {
    await openWorkspace(`/fornecedores/${supplier.id}`, path => path.includes('/documents')
      ? json({ data: [{ ...pdfDocument, status: 'ready' }], meta: { current_page: 1, last_page: 1, total: 1 } }) : json({ data: supplier }))
    expect(await screen.findByText(/pronto para análise/i)).toBeVisible()
    expect(screen.queryByText(/aguardando processamento/i)).not.toBeInTheDocument()
  })
  it('opens the latest completed analysis from the supplier dossier', async () => {
    await openWorkspace(`/fornecedores/${supplier.id}`, () => json({ data: { ...supplier, latest_analysis: { id: 'run-demo', status: 'completed' } } }), ['supplier.view', 'analysis.view'])
    expect(await screen.findByRole('link', { name: /matriz de conformidade/i })).toHaveAttribute('href', '/analises/run-demo/matriz')
  })
  it('reconciles a supplier saved before the initial GET completes with the older server list', async () => {
    let release!: (response: Response) => void
    await openWorkspace('/fornecedores', (_path, init) => init.method === 'POST' ? json({ data: supplier }, 201) : new Promise(resolve => { release = resolve }))
    await fireEvent.click(screen.getByRole('button', { name: 'Novo fornecedor' }))
    await fireEvent.update(screen.getByLabelText('Razão social'), supplier.name)
    await fireEvent.submit(screen.getByRole('form', { name: 'Cadastro do fornecedor' }))
    await screen.findByRole('status')
    release(json({ data: [{ ...supplier, id: 'older-id', name: 'VerdeLog' }] }))
    await flushPromises()
    expect(screen.getByRole('link', { name: supplier.name })).toBeVisible()
    expect(screen.getByRole('link', { name: 'VerdeLog' })).toBeVisible()
  })
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
