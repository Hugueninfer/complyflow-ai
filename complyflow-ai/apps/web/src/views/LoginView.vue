<script setup lang="ts">
import { computed, ref } from 'vue'
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
const showPassword = ref(false)
const error = ref('')
const action = ref<'login' | 'demo'>('login')
const message = computed(() => error.value || auth.bootstrapError || (route.query.expired ? 'Sua sessão expirou. Entre novamente para continuar.' : ''))

async function enter(mode: 'login' | 'demo') {
  if (auth.busy) return
  action.value = mode
  error.value = ''
  try {
    if (mode === 'demo') await auth.startDemo()
    else await auth.login(email.value.trim(), password.value)
    const redirect = route.query.redirect
    const target = typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') && redirect !== '/login' ? redirect : '/'
    await router.replace(target)
  } catch (cause) {
    error.value = cause instanceof ApiError && cause.status === 422 && mode === 'login'
      ? 'E-mail ou senha incorretos. Confira suas credenciais e tente novamente.'
      : cause instanceof Error ? cause.message : 'Não foi possível entrar. Tente novamente.'
  } finally { password.value = '' }
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
            Acesso à Plataforma Corporativa
          </h1>
          <p>Auditoria inteligente de fornecedores com soberania humana e rastreabilidade documental.</p>
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
          aria-label="Acesso à plataforma"
          class="login-form"
          :aria-busy="auth.busy"
          @submit.prevent="enter('login')"
        >
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
              />
            </div>
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
                autocomplete="current-password"
                placeholder="Insira sua senha"
                required
                :disabled="auth.busy"
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
              v-if="auth.busy && action === 'login'"
              class="spinner"
              aria-hidden="true"
            ></span>
            <LogIn
              v-else
              :size="19"
              aria-hidden="true"
            />
            {{ auth.busy && action === 'login' ? 'Entrando…' : 'Entrar na Plataforma' }}
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
