import { createPinia } from 'pinia'
import { render, fireEvent, screen, waitFor, within } from '@testing-library/vue'
import { createMemoryHistory } from 'vue-router'
import { describe, expect, it } from 'vitest'
import App from '../../App.vue'
import { createAppRouter } from '../../router'
import { useAuthStore } from '../../stores/auth'
import { demo, fakeServer, json, session } from '../../test/server'

async function openLogin(path = '/login') {
  const pinia = createPinia()
  const router = createAppRouter(pinia, createMemoryHistory())
  await router.push(path)
  await router.isReady()
  render(App, { global: { plugins: [pinia, router] } })
  return { router, auth: useAuthStore(pinia) }
}

describe('Login and protected navigation', () => {
  it('closes the mobile drawer when selecting the route that is already active', async () => {
    fakeServer(() => json({ data: session }))
    await openLogin('/')
    const trigger = screen.getByRole('button', { name: 'Abrir navegação' })
    await fireEvent.click(trigger)
    await fireEvent.click(within(screen.getByRole('dialog')).getByRole('link', { name: 'Visão Geral' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
    expect(document.body.style.overflow).not.toBe('hidden')
  })
  it('starts an isolated demo, restores server identity and opens the dashboard', async () => {
    let started = false
    fakeServer((path, init) => {
      if (path === '/api/v1/demo-sessions' && init.method === 'POST') { started = true; return json({ data: demo }, 201) }
      if (path === '/api/v1/me') return started ? json({ data: { ...session, demo } }) : json({}, 401)
      throw new Error(`Unexpected request ${path}`)
    })
    const { router, auth } = await openLogin()
    await fireEvent.click(screen.getByRole('button', { name: /explorar demonstração/i }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/'))
    expect(auth.session?.demo?.organization_id).toBe(session.organization.id)
    expect(screen.getByText(/dados fictícios · sessão isolada/i)).toBeVisible()
    expect(localStorage.length).toBe(0)
  })

  it('submits credentials and returns to the original protected route', async () => {
    let signedIn = false
    fakeServer((path, init) => {
      if (path === '/api/v1/me') return signedIn ? json({ data: session }) : json({}, 401)
      if (path === '/api/v1/login') {
        expect(JSON.parse(String(init.body))).toEqual({ email: 'ana@example.com', password: 'correct horse' })
        signedIn = true
        return json({ data: { user: session.user } })
      }
      throw new Error(`Unexpected request ${path}`)
    })
    const { router } = await openLogin('/fornecedores')
    expect(router.currentRoute.value.path).toBe('/login')
    await fireEvent.update(screen.getByLabelText('E-mail institucional'), 'ana@example.com')
    await fireEvent.update(screen.getByLabelText('Senha corporativa'), 'correct horse')
    await fireEvent.submit(screen.getByRole('form', { name: 'Acesso à plataforma' }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/fornecedores'))
  })

  it('disables both entry actions while preparing and announces retryable demo failure', async () => {
    let release!: (response: Response) => void
    fakeServer((path) => path === '/api/v1/me' ? json({}, 401) : new Promise(resolve => { release = resolve }))
    const { auth } = await openLogin()
    await fireEvent.click(screen.getByRole('button', { name: /explorar demonstração/i }))
    expect(screen.getByRole('button', { name: /preparando demonstração/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /entrar na plataforma/i })).toBeDisabled()
    await waitFor(() => expect(release).toBeTypeOf('function'))
    release(json({ message: 'Demo template exceeds quota.' }, 503))
    expect(await screen.findByRole('alert')).toHaveTextContent(/tente novamente/i)
    expect(auth.session).toBeNull()
    expect(screen.getByRole('button', { name: /explorar demonstração/i })).toBeEnabled()
  })

  it('labels invalid credentials and clears the submitted password', async () => {
    fakeServer(path => path === '/api/v1/me' ? json({}, 401) : json({ message: 'The provided credentials are incorrect.', errors: { email: ['The provided credentials are incorrect.'] } }, 422))
    await openLogin()
    await fireEvent.update(screen.getByLabelText('E-mail institucional'), 'ana@example.com')
    await fireEvent.update(screen.getByLabelText('Senha corporativa'), 'invalid')
    await fireEvent.submit(screen.getByRole('form', { name: 'Acesso à plataforma' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/e-mail ou senha/i)
    expect(screen.getByLabelText('Senha corporativa')).toHaveValue('')
  })

  it('restores a valid cookie session before permitting protected navigation', async () => {
    fakeServer(() => json({ data: session }))
    const { router, auth } = await openLogin('/')
    expect(router.currentRoute.value.path).toBe('/')
    expect(auth.session?.user.name).toBe('Ana Auditora')
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
  })

  it('clears the in-memory identity and redirects when the server returns 401', async () => {
    let expired = false
    fakeServer(() => expired ? json({}, 401) : json({ data: session }))
    const { router, auth } = await openLogin('/')
    expired = true
    const { api } = await import('../../lib/api')
    await expect(api.get('/suppliers')).rejects.toMatchObject({ status: 401 })
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
    expect(auth.session).toBeNull()
  })

  it('logs out on the server before removing protected content', async () => {
    let loggedOut = false
    fakeServer(path => {
      if (path === '/api/v1/logout') { loggedOut = true; return new Response(null, { status: 204 }) }
      return json({ data: session })
    })
    const { router, auth } = await openLogin('/')
    await fireEvent.click(screen.getByRole('button', { name: 'Sair da conta' }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
    expect(loggedOut).toBe(true)
    expect(auth.session).toBeNull()
  })

  it('keeps the session available and announces a failed logout', async () => {
    fakeServer(path => path === '/api/v1/logout' ? json({}, 503) : json({ data: session }))
    const { auth } = await openLogin('/')
    await fireEvent.click(screen.getByRole('button', { name: 'Sair da conta' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/tente novamente/i)
    expect(auth.session?.user.id).toBe(session.user.id)
  })

  it('does not expose a protected screen when bootstrap is unavailable', async () => {
    fakeServer(() => json({}, 503))
    await openLogin('/auditoria')
    expect(screen.getByRole('alert')).toHaveTextContent(/tente novamente/i)
    expect(screen.queryByRole('navigation', { name: 'Principal' })).not.toBeInTheDocument()
  })

  it('prevents review navigation for an analyst using server permissions', async () => {
    fakeServer(() => json({ data: { ...session, role: 'analyst', permissions: ['supplier.view'] } }))
    const { router } = await openLogin('/revisoes')
    expect(router.currentRoute.value.path).toBe('/sem-permissao')
    expect(screen.getByRole('heading', { name: 'Permissão insuficiente' })).toBeVisible()
    expect(screen.queryByRole('link', { name: 'Revisões' })).not.toBeInTheDocument()
  })

  it('opens the drawer, traps keyboard focus and restores the trigger on Escape', async () => {
    fakeServer(() => json({ data: session }))
    await openLogin('/')
    const trigger = screen.getByRole('button', { name: 'Abrir navegação' })
    trigger.focus()
    await fireEvent.click(trigger)
    const dialog = screen.getByRole('dialog', { name: 'Navegação principal' })
    expect(dialog).toBeVisible()
    const close = screen.getByRole('button', { name: 'Fechar navegação' })
    await waitFor(() => expect(close).toHaveFocus())
    await fireEvent.keyDown(close, { key: 'Tab', shiftKey: true })
    expect(dialog.querySelector('a:last-child')).toHaveFocus()
    let restoredInsideInertRegion = false
    trigger.addEventListener('focus', () => { restoredInsideInertRegion = trigger.closest('[inert]') !== null })
    await fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
    expect(restoredInsideInertRegion).toBe(false)
  })
})
