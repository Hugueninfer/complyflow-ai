<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, AtSign, Building2, Eye, EyeOff, FileText, Info, LockKeyhole, LogIn, Rocket, ShieldCheck, Sparkles } from '@lucide/vue'
import BrandLogo from '../components/ui/BrandLogo.vue'
import StatusBadge from '../components/ui/StatusBadge.vue'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../lib/api'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const name = ref(''), organizationName = ref(''), confirmation = ref('')
const mode = ref<'login' | 'register'>('login')
const fields = ref<Record<string, string>>({})
const form = ref<HTMLFormElement>()
const showPassword = ref(false)
const error = ref('')
const action = ref<'login' | 'register' | 'demo'>('login')
const message = computed(() => error.value || auth.bootstrapError || (route.query.expired ? 'Sua sessão expirou. Entre novamente para continuar.' : ''))
let active = true
function clearSecrets() { password.value = ''; confirmation.value = ''; showPassword.value = false }
function switchMode(value: 'login' | 'register') {
  if (auth.busy) return
  mode.value = value; fields.value = {}; error.value = ''; clearSecrets()
}
async function focusInvalid() {
  await nextTick()
  if (active) form.value?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus()
}
function validate() {
  if (!email.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) fields.value.email = 'Informe um e-mail válido.'
  if (!password.value) fields.value.password = 'Informe sua senha.'
  if (mode.value === 'register') {
    if (!name.value.trim()) fields.value.name = 'Informe seu nome.'
    if (!organizationName.value.trim()) fields.value.organization_name = 'Informe o nome da organização.'
    if (password.value.length < 12) fields.value.password = 'Use pelo menos 12 caracteres.'
    if (!confirmation.value || confirmation.value !== password.value) fields.value.password_confirmation = 'As senhas devem ser iguais.'
  }
  return Object.keys(fields.value).length === 0
}
onBeforeUnmount(() => { active = false; clearSecrets() })

async function enter(entry: 'login' | 'register' | 'demo') {
  if (auth.busy) return
  action.value = entry
  error.value = ''; fields.value = {}
  if (entry !== 'demo' && !validate()) { error.value = 'Verifique os campos destacados.'; await focusInvalid(); return }
  try {
    if (entry === 'demo') await auth.startDemo()
    else if (entry === 'register') await auth.register({ name: name.value.trim(), organization_name: organizationName.value.trim(), email: email.value, password: password.value, password_confirmation: confirmation.value })
    else await auth.login(email.value.trim(), password.value)
    if (!active) return
    const redirect = route.query.redirect
    const target = typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') && redirect !== '/login' ? redirect : '/'
    await router.replace(target)
  } catch (cause) {
    if (!active) return
    if (cause instanceof ApiError) fields.value = cause.fields
    error.value = cause instanceof ApiError && cause.status === 422 && entry === 'login'
      ? 'E-mail ou senha incorretos. Confira suas credenciais e tente novamente.'
      : cause instanceof Error ? cause.message : 'Não foi possível entrar. Tente novamente.'
    clearSecrets()
    await focusInvalid()
  } finally { clearSecrets() }
}
</script>

<template>
  <main class="login-layout">
    <section
      class="login-panel"
      aria-labelledby="login-title"
    >
      <div class="login-content">
        <header class="login-brand-row">
          <BrandLogo />
          <span class="small-tag">PORTFÓLIO · MVP</span>
        </header>
        <div class="login-heading">
          <p class="eyebrow">
            INTELIGÊNCIA COM RESPONSABILIDADE
          </p>
          <h1 id="login-title">
            {{ mode === 'register' ? 'Crie seu ambiente de conformidade' : 'Acesso à Plataforma Corporativa' }}
          </h1>
          <p>{{ mode === 'register' ? 'Cadastre sua conta de administrador e organize fornecedores, requisitos e evidências da sua organização.' : 'Auditoria inteligente de fornecedores com soberania humana e rastreabilidade documental.' }}</p>
        </div>
        <div
          class="access-switch"
          role="group"
          aria-label="Tipo de acesso"
        >
          <button
            type="button"
            :aria-pressed="mode === 'login'"
            :disabled="auth.busy"
            aria-controls="access-form"
            @click="switchMode('login')"
          >
            Entrar
          </button>
          <button
            type="button"
            :aria-pressed="mode === 'register'"
            :disabled="auth.busy"
            aria-controls="access-form"
            @click="switchMode('register')"
          >
            Criar conta
          </button>
        </div>
        <div
          v-if="message"
          class="error-notice"
          role="alert"
        >
          <Info
            :size="18"
            aria-hidden="true"
          />{{ message }}
        </div>
        <form
          id="access-form"
          ref="form"
          :aria-label="mode === 'register' ? 'Cadastro de conta' : 'Acesso à plataforma'"
          class="login-form"
          :aria-busy="auth.busy"
          novalidate
          @submit.prevent="enter(mode)"
        >
          <template v-if="mode === 'register'">
            <div class="form-field">
              <label for="name">Seu nome</label>
              <input
                id="name"
                v-model="name"
                name="name"
                autocomplete="name"
                maxlength="255"
                required
                :disabled="auth.busy"
                :aria-invalid="!!fields.name"
                :aria-describedby="fields.name ? 'name-error' : undefined"
              />
              <p
                v-if="fields.name"
                id="name-error"
                class="field-error"
              >
                {{ fields.name }}
              </p>
            </div>
            <div class="form-field">
              <label for="organization_name">Nome da organização</label>
              <input
                id="organization_name"
                v-model="organizationName"
                name="organization_name"
                autocomplete="organization"
                maxlength="255"
                required
                :disabled="auth.busy"
                :aria-invalid="!!fields.organization_name"
                :aria-describedby="fields.organization_name ? 'organization-error' : undefined"
              />
              <p
                v-if="fields.organization_name"
                id="organization-error"
                class="field-error"
              >
                {{ fields.organization_name }}
              </p>
            </div>
          </template>
          <div class="form-field">
            <label for="email">E-mail institucional</label>
            <div class="input-wrap">
              <AtSign
                :size="18"
                aria-hidden="true"
              />
              <input
                id="email"
                v-model="email"
                name="email"
                type="email"
                autocomplete="username"
                placeholder="nome@suaempresa.com.br"
                required
                :disabled="auth.busy"
                maxlength="255"
                :aria-invalid="!!fields.email"
                :aria-describedby="fields.email ? 'email-error' : undefined"
              />
            </div>
            <p
              v-if="fields.email"
              id="email-error"
              class="field-error"
            >
              {{ fields.email }}
            </p>
          </div>
          <div class="form-field">
            <label for="password">Senha corporativa</label>
            <div class="input-wrap">
              <LockKeyhole
                :size="18"
                aria-hidden="true"
              />
              <input
                id="password"
                v-model="password"
                name="password"
                :type="showPassword ? 'text' : 'password'"
                :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
                :minlength="mode === 'register' ? 12 : undefined"
                placeholder="Insira sua senha"
                required
                :disabled="auth.busy"
                :aria-invalid="!!fields.password"
                :aria-describedby="fields.password ? 'password-error' : mode === 'register' ? 'password-hint' : undefined"
              />
              <button
                class="password-toggle"
                type="button"
                :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
              >
                <component
                  :is="showPassword ? EyeOff : Eye"
                  :size="18"
                  aria-hidden="true"
                />
              </button>
            </div>
            <p
              v-if="fields.password"
              id="password-error"
              class="field-error"
            >
              {{ fields.password }}
            </p>
            <p
              v-else-if="mode === 'register'"
              id="password-hint"
              class="muted"
            >
              Use pelo menos 12 caracteres.
            </p>
          </div>
          <div
            v-if="mode === 'register'"
            class="form-field"
          >
            <label for="password_confirmation">Confirmar senha</label>
            <input
              id="password_confirmation"
              v-model="confirmation"
              name="password_confirmation"
              type="password"
              autocomplete="new-password"
              minlength="12"
              required
              :disabled="auth.busy"
              :aria-invalid="!!fields.password_confirmation"
              :aria-describedby="fields.password_confirmation ? 'confirmation-error' : undefined"
            />
            <p
              v-if="fields.password_confirmation"
              id="confirmation-error"
              class="field-error"
            >
              {{ fields.password_confirmation }}
            </p>
          </div>
          <p class="session-note">
            <ShieldCheck
              :size="15"
              aria-hidden="true"
            />Acesso protegido por sessão
          </p>
          <button
            type="submit"
            class="button button-primary login-submit"
            :disabled="auth.busy"
          >
            <span
              v-if="auth.busy && action !== 'demo'"
              class="spinner"
              aria-hidden="true"
            ></span>
            <LogIn
              v-else
              :size="19"
              aria-hidden="true"
            />
            {{ mode === 'register' ? (auth.busy && action === 'register' ? 'Criando conta…' : 'Criar conta e acessar') : (auth.busy && action === 'login' ? 'Entrando…' : 'Entrar na Plataforma') }}
          </button>
        </form>
        <div class="login-divider">
          <span>OU ACESSE O AMBIENTE DE AVALIAÇÃO</span>
        </div>
        <button
          class="demo-button"
          type="button"
          :disabled="auth.busy"
          @click="enter('demo')"
        >
          <span class="demo-icon"><span
            v-if="auth.busy && action === 'demo'"
            class="spinner"
            aria-hidden="true"
          ></span><Rocket
            v-else
            :size="24"
            aria-hidden="true"
          /></span>
          <span><strong>{{ auth.busy && action === 'demo' ? 'Preparando demonstração…' : 'Explorar Demonstração Interativa' }}</strong><span class="demo-description">Acesso instantâneo, sem credenciais</span></span>
          <ArrowRight
            class="demo-arrow"
            :size="20"
            aria-hidden="true"
          />
        </button>
        <div class="demo-note">
          <Info
            :size="19"
            aria-hidden="true"
          /><p><strong>Demonstração ativa por 24 horas.</strong> Uma organização exclusiva para você, com dados estritamente fictícios e limites de utilização.</p>
        </div>
      </div>
      <footer class="login-footer">
        <span>ComplyFlow AI</span><span>Análise assistida. Decisão humana.</span>
      </footer>
    </section>

    <section
      class="login-showcase"
      aria-label="Prévia ilustrativa da demonstração"
    >
      <div class="showcase-top">
        <span class="showcase-tag"><Sparkles
          :size="15"
          aria-hidden="true"
        />PRÉVIA ILUSTRATIVA</span><span class="mono">DADOS FICTÍCIOS</span>
      </div>
      <div class="showcase-content">
        <div class="showcase-heading">
          <p class="eyebrow">
            DA EVIDÊNCIA À DECISÃO
          </p><h2>Clareza para analisar.<br />Confiança para decidir.</h2><p>Documentos, requisitos e revisão humana conectados em um só lugar.</p>
        </div>
        <article class="evidence-preview">
          <header>
            <div>
              <h3>
                <Building2
                  :size="19"
                  aria-hidden="true"
                />NovaGuard Facilities
              </h3><p class="mono">
                FORNECEDOR FICTÍCIO · ATLAS INDUSTRIAL
              </p>
            </div><StatusBadge status="met" />
          </header>
          <div class="document-line">
            <FileText
              :size="18"
              aria-hidden="true"
            /><span>Certidão de regularidade.pdf</span><span class="mono">Pág. 1</span>
          </div>
          <blockquote><span class="mono">TRECHO DA EVIDÊNCIA</span><p>“Para fins desta demonstração, não constam pendências em nome do fornecedor acima identificado.”</p></blockquote>
          <div class="ai-suggestion">
            <Sparkles
              :size="15"
              aria-hidden="true"
            /><span>Sugestão da IA</span><span class="mono">98% de confiança · exemplo</span>
          </div>
          <div class="human-note">
            <ShieldCheck
              :size="21"
              aria-hidden="true"
            /><p><strong>Princípio da Soberania Humana</strong>A IA sugere. A revisão das evidências e a decisão final pertencem exclusivamente ao revisor humano.</p>
          </div>
        </article>
        <article class="preview-workflow">
          <h3>UM FLUXO COMPLETO, RASTREÁVEL</h3><div>
            <span><FileText
              :size="20"
              aria-hidden="true"
            /><strong>01</strong>Documentos</span><ArrowRight
              :size="16"
              aria-hidden="true"
            /><span><Sparkles
              :size="20"
              aria-hidden="true"
            /><strong>02</strong>Análise assistida</span><ArrowRight
              :size="16"
              aria-hidden="true"
            /><span><ShieldCheck
              :size="20"
              aria-hidden="true"
            /><strong>03</strong>Revisão humana</span>
          </div>
        </article>
      </div>
      <footer class="showcase-footer">
        <span><ShieldCheck
          :size="17"
          aria-hidden="true"
        />Evidências por página</span><span>Histórico de revisões</span><span>Decisão humana</span>
      </footer>
    </section>
  </main>
</template>
