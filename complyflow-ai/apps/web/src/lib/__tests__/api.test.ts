import { describe, expect, it } from 'vitest'
import { api } from '../api'
import { fakeServer, json } from '../../test/server'

describe('Sanctum transport', () => {
  it('sends multipart without a JSON content type and preserves dedup HTTP status', async () => {
    const body = new FormData()
    body.append('file', new File(['%PDF'], 'file.pdf', { type: 'application/pdf' }))
    fakeServer((_path, init) => {
      expect(init.body).toBe(body)
      expect(new Headers(init.headers).has('Content-Type')).toBe(false)
      return json({ data: { id: 'existing' } }, 200)
    })
    expect(await api.post('/suppliers/id/documents', body)).toMatchObject({ status: 200, data: { data: { id: 'existing' } } })
  })

  it('supports update/delete and localizes validation fields without reflecting arbitrary server messages', async () => {
    fakeServer((_path, init) => {
      if (init.method === 'DELETE') return new Response(null, { status: 204 })
      expect(init.method).toBe('PUT')
      return json({ errors: { tax_id: ['secret database trace'], 'requirements.0.code': ['internal detail'] } }, 422)
    })
    await expect(api.put('/suppliers/id', {})).rejects.toMatchObject({ fields: { tax_id: 'Verifique este campo.', 'requirements.0.code': 'Verifique este campo.' } })
    expect((await api.delete('/suppliers/id')).data).toBeUndefined()
  })
  it('initializes CSRF, includes session cookies and sends decoded XSRF for a mutation', async () => {
    const fetcher = fakeServer((path, init) => {
      expect(path).toBe('/api/v1/login')
      expect(init.credentials).toBe('include')
      expect(new Headers(init.headers).get('X-XSRF-TOKEN')).toBe('csrf=value')
      expect(JSON.parse(String(init.body))).toEqual({ email: 'ana@example.com', password: 'example' })
      return json({ data: { user: { name: 'Ana' } } })
    })
    const response = await api.post('/login', { email: 'ana@example.com', password: 'example' })
    expect(response.data).toEqual({ data: { user: { name: 'Ana' } } })
    expect(fetcher.mock.calls[0]?.[0]).toBe('/sanctum/csrf-cookie')
  })

  it('renews expired CSRF once and returns the successful response', async () => {
    let attempts = 0
    fakeServer(() => ++attempts === 1 ? json({ message: 'CSRF token mismatch.' }, 419) : json({ data: 'saved' }))
    expect((await api.post('/demo-sessions')).data).toEqual({ data: 'saved' })
    expect(attempts).toBe(2)
  })

  it('surfaces repeated 419 safely without an unbounded retry', async () => {
    let attempts = 0
    fakeServer(() => { attempts++; return json({ message: 'CSRF token mismatch.' }, 419) })
    await expect(api.post('/login')).rejects.toMatchObject({ status: 419 })
    expect(attempts).toBe(2)
  })

  it('handles 204 and sends credentials on GET', async () => {
    fakeServer((_path, init) => {
      expect(init.credentials).toBe('include')
      return new Response(null, { status: 204 })
    })
    expect((await api.get('/me')).data).toBeUndefined()
  })

  it('does not replay business requests after server failure', async () => {
    let attempts = 0
    fakeServer(() => { attempts++; return new Response('<html>internal stack trace</html>', { status: 500 }) })
    await expect(api.post('/demo-sessions')).rejects.toMatchObject({ status: 500, message: 'Não foi possível concluir a solicitação. Tente novamente.' })
    expect(attempts).toBe(1)
  })
})
