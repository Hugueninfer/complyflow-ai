import { vi } from 'vitest'

export const session = {
  user: { id: 'ed9909e1-8af8-4e27-a8d0-daa3bfc0dc20', name: 'Ana Auditora', email: 'ana@example.com' },
  organization: { id: '829909e1-8af8-4e27-a8d0-daa3bfc0dc20', name: 'Atlas Industrial' },
  role: 'owner',
  permissions: ['supplier.view', 'supplier.create', 'requirement.view', 'finding.review', 'audit.view'],
  demo: null,
}

export const demo = {
  id: '819909e1-8af8-4e27-a8d0-daa3bfc0dc20',
  organization_id: session.organization.id,
  expires_at: '2099-09-14T00:00:00.000000Z',
  quotas: { suppliers: 5, analyses: 10, storage_bytes: 15728640 },
}

export function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

// Only the network is replaced; components, stores, router and API remain real.
export function fakeServer(handler: (path: string, init: RequestInit) => Response | Promise<Response>) {
  const fetcher = vi.fn(async (input: string | URL | Request, init: RequestInit = {}) => {
    const path = String(input)
    if (path === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=csrf%3Dvalue; path=/'
      return new Response(null, { status: 204 })
    }
    return handler(path, init)
  })
  vi.stubGlobal('fetch', fetcher)
  return fetcher
}
