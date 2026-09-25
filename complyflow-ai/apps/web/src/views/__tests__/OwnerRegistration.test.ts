import { createPinia } from 'pinia'
import { render, fireEvent, screen, waitFor, within } from '@testing-library/vue'
import { createMemoryHistory } from 'vue-router'
import { describe, expect, it } from 'vitest'
import App from '../../App.vue'
import { createAppRouter } from '../../router'
import { useAuthStore } from '../../stores/auth'
import { fakeServer, json, session } from '../../test/server'

async function openRegistration(handler: Parameters<typeof fakeServer>[0]) {
  fakeServer(handler)
  const pinia = createPinia()
  const router = createAppRouter(pinia, createMemoryHistory())
  await router.push('/login')
  render(App, { global: { plugins: [pinia, router] } })
  await fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))
  return { router, auth: useAuthStore(pinia) }
}

async function fillRegistration(password = 'correct horse battery staple', confirmation = password) {
  await fireEvent.update(screen.getByLabelText('Seu nome'), 'Ana Owner')
  await fireEvent.update(screen.getByLabelText('Nome da organização'), 'Atlas Nova')
  await fireEvent.update(screen.getByLabelText('E-mail institucional'), ' ANA@EXAMPLE.COM ')
  await fireEvent.update(screen.getByLabelText('Senha corporativa'), password)
  await fireEvent.update(screen.getByLabelText('Confirmar senha'), confirmation)
}

describe('Owner registration', () => {
  it('registers through the API and waits for the authoritative owner session before navigation', async () => {
    let registered = false
    let release!: (response: Response) => void
    const { router, auth } = await openRegistration((path, init) => {
      if (path === '/api/v1/me') return registered ? new Promise(resolve => { release = resolve }) : json({}, 401)
      if (path === '/api/v1/register') {
        expect(JSON.parse(String(init.body))).toEqual({ name: 'Ana Owner', organization_name: 'Atlas Nova', email: 'ana@example.com', password: 'correct horse battery staple', password_confirmation: 'correct horse battery staple' })
        registered = true
        return json({ data: { user: session.user, organization: session.organization } }, 201)
      }
      if (path === '/api/v1/dashboard') return json({ data: { suppliers_analyzed: 0, requirements_met: 0, pending_requirements: 0, analyses_awaiting_review: 0 } })
      throw new Error(path)
    })
    await fillRegistration()
    await fireEvent.submit(screen.getByRole('form', { name: 'Cadastro de conta' }))
    await waitFor(() => expect(release).toBeTypeOf('function'))
    expect(router.currentRoute.value.path).toBe('/login')
    expect(screen.getByRole('button', { name: /criando conta/i })).toBeDisabled()
    release(json({ data: { ...session, role: 'owner', demo: null } }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/'))
    expect(auth.session?.role).toBe('owner')
    expect(auth.session?.demo).toBeNull()
    expect(localStorage.length).toBe(0)
  })

  it('validates required fields, 12 characters and matching confirmation before sending', async () => {
    let posts = 0
    await openRegistration(() => { posts++; return json({}, 401) })
    const form = screen.getByRole('form', { name: 'Cadastro de conta' })
    await fireEvent.submit(form)
    for (const label of ['Seu nome', 'Nome da organização', 'E-mail institucional', 'Senha corporativa', 'Confirmar senha']) {
      expect(screen.getByLabelText(label)).toHaveAttribute('aria-invalid', 'true')
    }
    await fillRegistration('short')
    await fireEvent.submit(form)
    expect(screen.getByLabelText('Senha corporativa')).toHaveAccessibleDescription(/12 caracteres/i)
    await fillRegistration('correct horse battery staple', 'different password')
    await fireEvent.submit(form)
    expect(screen.getByLabelText('Confirmar senha')).toHaveAccessibleDescription(/senhas.*iguais/i)
    expect(posts).toBe(1) // Only the initial GET /me.
  })

  it('announces safe field errors, clears secrets and blocks repeat submits or mode switching while pending', async () => {
    let release!: (response: Response) => void
    let posts = 0
    await openRegistration(path => path === '/api/v1/me' ? json({}, 401) : new Promise(resolve => { posts++; release = resolve }))
    await fillRegistration()
    const form = screen.getByRole('form', { name: 'Cadastro de conta' })
    await fireEvent.submit(form)
    await fireEvent.submit(form)
    expect(within(screen.getByRole('group', { name: 'Tipo de acesso' })).getByRole('button', { name: 'Entrar' })).toBeDisabled()
    expect(screen.getByRole('button', { name: /explorar demonstração/i })).toBeDisabled()
    await waitFor(() => expect(posts).toBe(1))
    release(json({ errors: { email: ['sensitive SQL detail'], organization_name: ['private detail'] } }, 422))
    await waitFor(() => expect(screen.getByLabelText('E-mail institucional')).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByLabelText('Nome da organização')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveTextContent(/verifique/i)
    expect(screen.queryByText(/sensitive SQL|private detail/)).not.toBeInTheDocument()
    expect(screen.getByLabelText('Senha corporativa')).toHaveValue('')
    expect(screen.getByLabelText('Confirmar senha')).toHaveValue('')
    expect(screen.getByRole('button', { name: /criar conta e acessar/i })).toBeEnabled()
  })

  it('clears registration secrets and validation when switching back to login', async () => {
    await openRegistration(() => json({}, 401))
    await fillRegistration()
    await fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(screen.getByRole('form', { name: 'Acesso à plataforma' })).toBeVisible()
    expect(screen.getByLabelText('Senha corporativa')).toHaveValue('')
    expect(screen.queryByLabelText('Confirmar senha')).not.toBeInTheDocument()
    await fireEvent.click(screen.getByRole('button', { name: 'Criar conta' }))
    expect(screen.getByLabelText('Confirmar senha')).toHaveValue('')
  })
})
