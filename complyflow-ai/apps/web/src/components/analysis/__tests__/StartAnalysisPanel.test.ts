import { createPinia } from 'pinia'
import { render, fireEvent, screen, waitFor, within } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
import StartAnalysisPanel from '../StartAnalysisPanel.vue'
import { fakeServer, json, session } from '../../../test/server'
import { pdfDocument, requirementSet, supplier } from '../../../test/workspace'
import { run } from '../../../test/analysis'
import { useAuthStore } from '../../../stores/auth'
import type { Session } from '../../../types/domain'

const ready = { ...pdfDocument, status: 'ready' }
const published = { ...requirementSet, status: 'published', published_at: '2026-09-13T00:00:00Z' }
function page(documents = [ready]) { return { data: documents, meta: { current_page: 1, last_page: 1, total: documents.length } } }
async function openPanel(handler?: Parameters<typeof fakeServer>[0], access: Pick<Session, 'role' | 'permissions'> = { role: 'owner', permissions: ['analysis.run', 'requirement.view', 'requirement.create', 'requirement.publish', 'document.view', 'document.upload'] }) {
  fakeServer((path, init) => handler ? handler(path, init) : path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page()))
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/fornecedores/:id', component: { template: '<p>Dossiê</p>' } },
    { path: '/analises/:id', component: { template: '<p>Progresso</p>' } },
    { path: '/requisitos', component: { template: '<p>Requisitos</p>' } },
    { path: '/fornecedores/:id/documentos', component: { template: '<p>Upload</p>' } },
  ] })
  const pinia = createPinia()
  useAuthStore(pinia).session = { ...session, ...access }
  await router.push(`/fornecedores/${supplier.id}`)
  const view = render(StartAnalysisPanel, { props: { supplierId: supplier.id }, global: { plugins: [router, pinia] } })
  return { router, ...view }
}
async function selectInputs() {
  await fireEvent.update(await screen.findByLabelText('Conjunto de requisitos'), published.id)
  await fireEvent.click(await screen.findByRole('checkbox', { name: /generated.pdf/i }))
}

describe('Start analysis selection', () => {
  it('lists published versions and uploaded or ready PDFs, excluding other states even across pages', async () => {
    await openPanel(path => {
      if (path === '/api/v1/requirement-sets') return json({ data: [published, { ...requirementSet, id: 'draft', name: 'Rascunho' }] })
      if (path.endsWith('page=2')) return json({ ...page([{ ...ready, id: 'second-page', storage_name: 'second.pdf' }]), meta: { current_page: 2, last_page: 2, total: 5 } })
      return json({ data: [ready, ...['uploaded', 'processing', 'failed', 'unknown'].map(status => ({ ...ready, id: status, storage_name: status + '.pdf', status }))], meta: { current_page: 1, last_page: 2, total: 6 } })
    })
    expect(await screen.findByRole('checkbox', { name: /second.pdf/i })).toBeVisible()
    expect(screen.getAllByRole('checkbox')).toHaveLength(3)
    expect(screen.getByRole('checkbox', { name: /uploaded.pdf/i })).toBeVisible()
    expect(screen.queryByRole('option', { name: /rascunho/i })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Iniciar análise documental' })).toBeDisabled()
  })

  it('offers an owner with create/publish permission the actual creation/upload routes', async () => {
    await openPanel(path => path === '/api/v1/requirement-sets' ? json({ data: [requirementSet] }) : json(page([])))
    expect(await screen.findByText(/nenhuma versão publicada/i)).toBeVisible()
    expect(screen.getByRole('link', { name: /criar e publicar/i })).toHaveAttribute('href', '/requisitos')
    expect(screen.getByRole('link', { name: /enviar pdf/i })).toHaveAttribute('href', `/fornecedores/${supplier.id}/documentos`)
    expect(screen.getByRole('button', { name: 'Iniciar análise documental' })).toBeDisabled()
  })

  it('offers an analyst draft creation without implying they can publish', async () => {
    await openPanel(path => path === '/api/v1/requirement-sets' ? json({ data: [] }) : json(page()), {
      role: 'analyst', permissions: ['analysis.run', 'requirement.view', 'requirement.create', 'requirement.update', 'document.view', 'document.upload'],
    })
    expect(await screen.findByRole('link', { name: /criar rascunho/i })).toHaveAttribute('href', '/requisitos')
    expect(screen.queryByRole('link', { name: /publicar/i })).not.toBeInTheDocument()
    expect(screen.getByText(/solicite a publicação ao administrador da organização/i)).toBeVisible()
  })

  it('provides only publication guidance with read-only requirement permissions', async () => {
    await openPanel(path => path === '/api/v1/requirement-sets' ? json({ data: [] }) : json(page()), {
      role: 'reviewer', permissions: ['requirement.view', 'document.view'],
    })
    expect(await screen.findByText(/nenhuma versão publicada/i)).toBeVisible()
    expect(screen.queryByRole('link', { name: /criar|publicar/i })).not.toBeInTheDocument()
    expect(screen.getByText(/solicite ao administrador da organização um conjunto publicado/i)).toBeVisible()
  })

  it.each([202, 200])('opens the returned run for a %i response and sends a UUID key once', async status => {
    let release!: (response: Response) => void
    const submissions: RequestInit[] = []
    const { router } = await openPanel((path, init) => {
      if (init.method === 'POST') return new Promise(resolve => { submissions.push(init); release = resolve })
      return path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page())
    })
    await selectInputs()
    const form = screen.getByRole('form', { name: 'Iniciar análise documental' })
    await fireEvent.submit(form)
    await fireEvent.submit(form)
    await waitFor(() => expect(submissions).toHaveLength(1))
    expect(JSON.parse(String(submissions[0]!.body))).toEqual({ requirement_set_id: published.id, document_ids: [ready.id] })
    expect(new Headers(submissions[0]!.headers).get('Idempotency-Key')).toMatch(/^analysis-ui:[0-9a-f-]{36}$/)
    expect(screen.getByRole('button', { name: /iniciando/i })).toBeDisabled()
    expect(screen.getByRole('checkbox')).toBeDisabled()
    release(json({ data: run }, status))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/analises/run-1'))
  })

  it('limits selection to ten and rejects a batch larger than 15 MiB before sending', async () => {
    const documents = Array.from({ length: 11 }, (_, index) => ({ ...ready, id: String(index), storage_name: `${index}.pdf`, size_bytes: 2 * 1024 * 1024 }))
    let posts = 0
    await openPanel((path, init) => {
      if (init.method === 'POST') { posts++; return json({ data: run }, 202) }
      return path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page(documents))
    })
    await fireEvent.update(await screen.findByLabelText('Conjunto de requisitos'), published.id)
    for (const checkbox of screen.getAllByRole('checkbox').slice(0, 10)) await fireEvent.click(checkbox)
    expect(screen.getAllByRole('checkbox')[10]).toBeDisabled()
    await fireEvent.submit(screen.getByRole('form'))
    expect(screen.getByRole('alert')).toHaveTextContent(/15 MiB/i)
    expect(posts).toBe(0)
  })

  it('reuses the key after a lost response and changes it for an edited selection', async () => {
    const keys: string[] = []
    await openPanel((path, init) => {
      if (init.method === 'POST') { keys.push(new Headers(init.headers).get('Idempotency-Key')!); return Promise.reject(new TypeError('network unavailable')) }
      return path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page([ready, { ...ready, id: 'extra', storage_name: 'extra.pdf' }]))
    })
    await selectInputs()
    for (let attempt = 0; attempt < 2; attempt++) {
      await fireEvent.submit(screen.getByRole('form'))
      await waitFor(() => expect(screen.getByRole('button', { name: 'Iniciar análise documental' })).toBeEnabled())
    }
    expect(keys).toHaveLength(2)
    expect(keys[1]).toBe(keys[0])
    await fireEvent.click(screen.getByRole('checkbox', { name: /extra.pdf/i }))
    await fireEvent.submit(screen.getByRole('form'))
    await waitFor(() => expect(keys).toHaveLength(3))
    expect(keys[2]).not.toBe(keys[1])
  })

  it.each([409, 422, 429, 401])('announces a safe %i response and never navigates on failure', async status => {
    const { router } = await openPanel((path, init) => init.method === 'POST'
      ? json({ message: 'private database detail', errors: { 'document_ids.0': ['private database detail'], requirement_set_id: ['private database detail'] } }, status)
      : path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page()))
    await selectInputs()
    await fireEvent.submit(screen.getByRole('form'))
    expect(await screen.findByRole('alert')).not.toHaveTextContent('private database detail')
    expect(router.currentRoute.value.path).toBe(`/fornecedores/${supplier.id}`)
    if (status === 422) {
      expect(screen.getByLabelText('Conjunto de requisitos')).toHaveAttribute('aria-invalid', 'true')
      expect(screen.getByRole('group', { name: /documentos pdf/i })).toHaveAccessibleDescription(/verifique/i)
    }
    if (status === 401) expect(screen.getByRole('button', { name: 'Iniciar análise documental' })).toBeDisabled()
  })

  it('rotates a rejected conflicting key for an explicit retry', async () => {
    const keys: string[] = []
    await openPanel((path, init) => {
      if (init.method === 'POST') { keys.push(new Headers(init.headers).get('Idempotency-Key')!); return json({}, 409) }
      return path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page())
    })
    await selectInputs()
    await fireEvent.submit(screen.getByRole('form'))
    await screen.findByRole('alert')
    await fireEvent.submit(screen.getByRole('form'))
    await waitFor(() => expect(keys).toHaveLength(2))
    expect(keys[1]).not.toBe(keys[0])
  })

  it('ignores an old supplier response after the component changes supplier', async () => {
    let release!: (response: Response) => void
    const { rerender } = await openPanel(path => {
      if (path === '/api/v1/requirement-sets') return json({ data: [published] })
      if (path.includes(supplier.id)) return new Promise(resolve => { release = resolve })
      return json(page([{ ...ready, id: 'new', storage_name: 'new-supplier.pdf' }]))
    })
    await waitFor(() => expect(release).toBeTypeOf('function'))
    await rerender({ supplierId: 'new-supplier' })
    await screen.findByRole('checkbox', { name: /new-supplier.pdf/i })
    release(json(page()))
    await flushPromises()
    expect(screen.queryByRole('checkbox', { name: /generated.pdf/i })).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /new-supplier.pdf/i })).toBeVisible()
  })

  it.each(['unmount', 'supplier change'])('ignores a late creation after %s', async leave => {
    let release!: (response: Response) => void
    const { router, rerender, unmount } = await openPanel((path, init) => init.method === 'POST'
      ? new Promise(resolve => { release = resolve })
      : path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page()))
    await selectInputs()
    await fireEvent.submit(screen.getByRole('form'))
    await waitFor(() => expect(release).toBeTypeOf('function'))
    if (leave === 'unmount') unmount()
    else await rerender({ supplierId: 'new-supplier' })
    // Vue Router resets its location when the last application using it unmounts.
    const locationAfterLeaving = router.currentRoute.value.path
    release(json({ data: run }, 202))
    await flushPromises()
    expect(router.currentRoute.value.path).toBe(locationAfterLeaving)
  })

  it('retries unavailable prerequisites without leaving an old GET in control', async () => {
    let fail = true
    await openPanel(path => fail ? json({}, 503) : path === '/api/v1/requirement-sets' ? json({ data: [published] }) : json(page()))
    await screen.findByRole('alert')
    fail = false
    await fireEvent.click(within(screen.getByRole('alert')).getByRole('button', { name: /tentar novamente/i }))
    expect(await screen.findByRole('checkbox')).toBeVisible()
  })
})
