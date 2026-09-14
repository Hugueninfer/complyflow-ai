<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Building2, ClipboardCheck, FileCheck2, GitCompareArrows, LayoutDashboard, ListChecks, LogOut, Menu, CircleHelp, ShieldCheck, Sparkles, UserRound, X } from '@lucide/vue'
import BrandLogo from '../ui/BrandLogo.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const drawerOpen = ref(false)
const drawer = ref<HTMLElement>()
const trigger = ref<HTMLButtonElement>()
const logoutError = ref('')
const navItems = [
  { path: '/', label: 'Visão Geral', icon: LayoutDashboard },
  { path: '/fornecedores', label: 'Fornecedores', icon: Building2, permission: 'supplier.view' },
  { path: '/requisitos', label: 'Requisitos', icon: ListChecks, permission: 'requirement.view' },
  { path: '/analises', label: 'Análises', icon: Sparkles, permission: 'analysis.view' },
  { path: '/revisoes', label: 'Revisões', icon: ClipboardCheck, permission: 'finding.review' },
  { path: '/comparacoes', label: 'Comparações', icon: GitCompareArrows, permission: 'supplier.view' },
  { path: '/auditoria', label: 'Auditoria', icon: ShieldCheck, permission: 'audit.view' },
  { path: '/ajuda', label: 'Central de Ajuda', icon: CircleHelp },
]
const navigation = computed(() => navItems.filter(item => !item.permission || auth.can(item.permission)))
const roleLabel = computed(() => ({ owner: 'Administrador', analyst: 'Analista', reviewer: 'Revisor' })[auth.session?.role ?? 'analyst'])
let priorOverflow = ''

async function openDrawer() {
  priorOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  drawerOpen.value = true
  await nextTick()
  drawer.value?.querySelector<HTMLButtonElement>('button')?.focus()
}
function closeDrawer() {
  if (!drawerOpen.value) return
  drawerOpen.value = false
  document.body.style.overflow = priorOverflow
  // Wait until Vue removes inert from the canvas before restoring browser focus.
  void nextTick(() => trigger.value?.focus())
}
function drawerKey(event: KeyboardEvent) {
  if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return }
  if (event.key !== 'Tab') return
  const controls = drawer.value?.querySelectorAll<HTMLElement>('button, a[href]')
  const first = controls?.[0]
  const last = controls?.[controls.length - 1]
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
watch(() => route.fullPath, closeDrawer)
onBeforeUnmount(closeDrawer)

async function logout() {
  logoutError.value = ''
  try { await auth.logout(); await router.replace('/login') }
  catch (error) { logoutError.value = error instanceof Error ? error.message : 'Não foi possível sair. Tente novamente.' }
}
</script>

<template>
  <div class="app-shell">
    <a
      class="skip-link"
      href="#main-content"
    >Pular para o conteúdo</a>
    <aside
      class="sidebar"
      :inert="drawerOpen || undefined"
    >
      <BrandLogo inverse />
      <p class="sidebar-organization">
        {{ auth.session?.organization.name }}
      </p>
      <nav aria-label="Principal">
        <RouterLink
          v-for="item in navigation"
          :key="item.path"
          :to="item.path"
          :class="{ active: route.path === item.path || (item.path !== '/' && route.path.startsWith(`${item.path}/`)) }"
        >
          <component
            :is="item.icon"
            :size="18"
            aria-hidden="true"
          />{{ item.label }}
        </RouterLink>
      </nav>
      <div class="sidebar-profile">
        <UserRound
          :size="28"
          aria-hidden="true"
        /><div><strong>{{ auth.session?.user.name }}</strong><span>{{ roleLabel }}</span></div>
      </div>
    </aside>
    <div
      class="app-canvas"
      :inert="drawerOpen || undefined"
    >
      <header class="topbar">
        <button
          ref="trigger"
          class="icon-button menu-trigger"
          aria-label="Abrir navegação"
          :aria-expanded="drawerOpen"
          aria-controls="mobile-navigation"
          @click="openDrawer"
        >
          <Menu
            :size="22"
            aria-hidden="true"
          />
        </button>
        <div class="breadcrumb">
          <FileCheck2
            :size="18"
            aria-hidden="true"
          /><span>Workspace</span><span aria-hidden="true">/</span><strong>{{ route.meta.title }}</strong>
        </div>
        <span
          v-if="auth.session?.demo"
          class="demo-session-tag"
        >Dados fictícios · sessão isolada</span>
        <button
          class="icon-button logout-button"
          aria-label="Sair da conta"
          :disabled="auth.busy"
          @click="logout"
        >
          <LogOut
            :size="18"
            aria-hidden="true"
          /><span>{{ auth.busy ? 'Saindo…' : 'Sair' }}</span>
        </button>
      </header>
      <main
        id="main-content"
        class="workspace-content"
        tabindex="-1"
      >
        <div
          v-if="logoutError"
          class="error-notice"
          role="alert"
        >
          {{ logoutError }}
        </div>
        <div class="sovereignty-banner">
          <span class="sovereignty-icon"><ShieldCheck
            :size="21"
            aria-hidden="true"
          /></span><p><strong>Princípio da Soberania Humana em Conformidade</strong>A IA auxilia na análise documental. A decisão final é sempre do revisor humano.</p>
        </div>
        <slot></slot>
      </main>
    </div>
    <div
      v-if="drawerOpen"
      class="drawer-backdrop"
      @click.self="closeDrawer"
    >
      <section
        id="mobile-navigation"
        ref="drawer"
        class="mobile-drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Navegação principal"
        @keydown="drawerKey"
      >
        <div class="drawer-heading">
          <BrandLogo inverse /><button
            class="icon-button"
            aria-label="Fechar navegação"
            @click="closeDrawer"
          >
            <X
              :size="22"
              aria-hidden="true"
            />
          </button>
        </div>
        <nav aria-label="Navegação móvel">
          <RouterLink
            v-for="item in navigation"
            :key="item.path"
            :to="item.path"
            :class="{ active: route.path === item.path || (item.path !== '/' && route.path.startsWith(`${item.path}/`)) }"
            @click="closeDrawer"
          >
            <component
              :is="item.icon"
              :size="18"
              aria-hidden="true"
            />{{ item.label }}
          </RouterLink>
        </nav>
      </section>
    </div>
  </div>
</template>
