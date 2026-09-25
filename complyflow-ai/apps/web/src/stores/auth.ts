import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, ApiError } from '../lib/api'
import type { Envelope, Session } from '../types/domain'

export interface Registration { name: string; organization_name: string; email: string; password: string; password_confirmation: string }

export const useAuthStore = defineStore('auth', () => {
  const session = ref<Session | null>(null)
  const initialized = ref(false)
  const bootstrapError = ref('')
  const busy = ref(false)
  const isAuthenticated = computed(() => session.value !== null)
  let boot: Promise<void> | undefined

  function clearSession() { session.value = null }
  function can(permission: string) { return session.value?.permissions.includes(permission) ?? false }

  async function refresh() {
    const response = await api.get<Envelope<Session>>('/me')
    session.value = response.data.data
    bootstrapError.value = ''
    initialized.value = true
  }

  async function bootstrap() {
    if (initialized.value) return
    if (boot) return boot
    boot = (async () => {
      try { await refresh() }
      catch (error) {
        clearSession()
        if (!(error instanceof ApiError && error.status === 401)) bootstrapError.value = error instanceof Error ? error.message : 'Tente novamente.'
      } finally { initialized.value = true; boot = undefined }
    })()
    return boot
  }

  async function enter(path: string, credentials?: { email: string; password: string } | Registration) {
    if (busy.value) return
    busy.value = true
    bootstrapError.value = ''
    try {
      await api.post(path, credentials)
      await refresh()
    } catch (error) {
      clearSession()
      throw error
    } finally { busy.value = false }
  }

  async function login(email: string, password: string) { await enter('/login', { email: email.trim().toLowerCase(), password }) }
  async function register(credentials: Registration) { await enter('/register', { ...credentials, email: credentials.email.trim().toLowerCase() }) }
  async function startDemo() { await enter('/demo-sessions') }
  async function logout() {
    if (busy.value) return
    busy.value = true
    try {
      await api.post('/logout')
      clearSession()
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) clearSession()
      else throw error
    } finally { busy.value = false }
  }

  return { session, initialized, bootstrapError, busy, isAuthenticated, can, bootstrap, refresh, login, register, startDemo, logout, clearSession }
})
