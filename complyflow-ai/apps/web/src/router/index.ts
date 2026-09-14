import { createRouter, createWebHistory, type RouterHistory } from 'vue-router'
import type { Pinia } from 'pinia'
import { useAuthStore } from '../stores/auth'
import { onUnauthorized } from '../lib/api'
import LoginView from '../views/LoginView.vue'
import WorkspaceView from '../views/WorkspaceView.vue'
import SuppliersView from '../views/SuppliersView.vue'
import SupplierDetailView from '../views/SupplierDetailView.vue'
import RequirementsView from '../views/RequirementsView.vue'
import DocumentUploadView from '../views/DocumentUploadView.vue'

declare module 'vue-router' {
  interface RouteMeta { requiresAuth?: boolean; title?: string; permission?: string }
}

export function createAppRouter(pinia: Pinia, history: RouterHistory = createWebHistory()) {
  const auth = useAuthStore(pinia)
  const router = createRouter({
    history,
    routes: [
      { path: '/login', name: 'login', component: LoginView, meta: { title: 'Acesso à plataforma' } },
      { path: '/fornecedores', component: SuppliersView, meta: { requiresAuth: true, title: 'Fornecedores', permission: 'supplier.view' } },
      { path: '/fornecedores/:id', component: SupplierDetailView, meta: { requiresAuth: true, title: 'Dossiê do fornecedor', permission: 'supplier.view' } },
      { path: '/fornecedores/:id/documentos', component: DocumentUploadView, meta: { requiresAuth: true, title: 'Envio de documentos', permission: 'document.upload' } },
      { path: '/requisitos', component: RequirementsView, meta: { requiresAuth: true, title: 'Requisitos', permission: 'requirement.view' } },
      ...[
        ['/', 'Visão Geral', ''],
        ['/analises', 'Análises', 'analysis.view'],
        ['/revisoes', 'Revisões', 'finding.review'], ['/comparacoes', 'Comparações', 'supplier.view'],
        ['/auditoria', 'Auditoria', 'audit.view'], ['/ajuda', 'Central de Ajuda', ''],
      ].map(([path, title, permission]) => ({ path: path!, component: WorkspaceView, meta: { requiresAuth: true, title, permission } })),
      { path: '/sem-permissao', component: WorkspaceView, meta: { requiresAuth: true, title: 'Permissão insuficiente' } },
      { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
  })
  router.beforeEach(async to => {
    await auth.bootstrap()
    if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
    if (to.meta.permission && !auth.can(to.meta.permission)) return '/sem-permissao'
    if (to.name === 'login' && auth.isAuthenticated) return '/'
  })
  router.afterEach(to => { document.title = `${to.meta.title ?? 'Conformidade documental'} | ComplyFlow AI` })
  onUnauthorized(() => {
    auth.clearSession()
    if (router.currentRoute.value.meta.requiresAuth) void router.replace({ name: 'login', query: { expired: '1' } })
  })
  return router
}
