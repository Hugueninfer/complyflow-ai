import { createPinia } from 'pinia'
import { render } from '@testing-library/vue'
import { createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { createAppRouter } from '../router'
import { fakeServer, json, session } from './server'

export const permissions = ['supplier.view', 'supplier.create', 'supplier.update', 'document.view', 'document.upload', 'requirement.view', 'requirement.create', 'requirement.update', 'requirement.publish']
export const supplier = { id: 'ed9909e1-8af8-4e27-a8d0-daa3bfc0dc21', name: 'NovaGuard Facilities', tax_id: '42.108.921/0001-84', risk_level: 'high' }
export const pdfDocument = { id: 'ed9909e1-8af8-4e27-a8d0-daa3bfc0dc22', storage_name: 'generated.pdf', mime_type: 'application/pdf', size_bytes: 1024, sha256: 'a'.repeat(64), status: 'uploaded' }
export const requirement = { id: 'req-1', code: 'FISC-01', title: 'Regularidade fiscal', category: 'Fiscal', weight: '1.000', position: 0, evaluation_text: 'Certidão válida', is_required: true }
export const requirementSet = { id: 'set-1', name: 'Homologação', version: 1, status: 'draft', published_at: null, requirements: [requirement] }
export async function openWorkspace(path: string, handler: (path: string, init: RequestInit) => Response | Promise<Response>, allowed = permissions) {
  fakeServer((url, init) => url === '/api/v1/me' ? json({ data: { ...session, permissions: allowed } }) : handler(url, init))
  const pinia = createPinia()
  const router = createAppRouter(pinia, createMemoryHistory())
  await router.push(path)
  render(App, { global: { plugins: [pinia, router] } })
  return router
}
