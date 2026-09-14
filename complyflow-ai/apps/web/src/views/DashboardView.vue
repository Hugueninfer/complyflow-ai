<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Building2, ListChecks, Clock3, ShieldAlert, ArrowUpRight, GitCompareArrows } from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useRemoteResource } from '../composables/useRemoteResource'
import type { Envelope } from '../types/domain'
import type { DashboardMetrics } from '../types/portfolio'
import ResourceState from '../components/ui/ResourceState.vue'
import KpiCard from '../components/dashboard/KpiCard.vue'
import '../styles/portfolio.css'
const auth = useAuthStore()
const { data, loading, error, load } = useRemoteResource<Envelope<DashboardMetrics>>()
onMounted(() => load('/dashboard'))
</script>
<template>
  <header class="surface-card portfolio-welcome">
    <div>
      <p class="eyebrow">
        PAINEL OPERACIONAL
      </p><h1>Visão geral</h1><p class="muted">
        Acompanhe a conformidade documental da sua organização.
      </p>
    </div>
    <RouterLink
      v-if="auth.can('supplier.view')"
      class="button button-primary"
      to="/fornecedores"
    >
      Explorar fornecedores<ArrowUpRight
        :size="18"
        aria-hidden="true"
      />
    </RouterLink>
  </header>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando indicadores"
    @retry="load('/dashboard')"
  >
    <template v-if="data?.data">
      <div class="portfolio-kpis">
        <KpiCard
          label="Fornecedores analisados"
          :value="data.data.suppliers_analyzed"
          description="Análises atuais concluídas"
          :icon="Building2"
        />
        <KpiCard
          label="Requisitos atendidos"
          :value="data.data.requirements_met"
          description="Status efetivo por requisito"
          :icon="ListChecks"
          tone="teal"
        />
        <KpiCard
          label="Pendências"
          :value="data.data.pending_requirements"
          description="Requisitos parciais, ausentes ou inconclusivos"
          :icon="ShieldAlert"
          tone="amber"
        />
        <KpiCard
          label="Aguardando revisão"
          :value="data.data.analyses_awaiting_review"
          description="Análises com itens obrigatórios por revisar"
          :icon="Clock3"
        />
      </div>
      <p
        v-if="!data.data.suppliers_analyzed"
        class="surface-card portfolio-empty"
      >
        Nenhuma análise atual concluída. Abra um fornecedor para enviar documentos e iniciar a análise.
      </p>
      <div class="portfolio-columns">
        <section class="surface-card portfolio-section">
          <p class="eyebrow">
            CONTINUE A JORNADA
          </p><h2>Da evidência à decisão humana</h2>
          <RouterLink
            v-if="auth.can('supplier.view')"
            class="journey-link"
            to="/fornecedores"
          >
            <Building2
              :size="22"
              aria-hidden="true"
            /><span><strong>Documentos e análises</strong><small>Abra o dossiê, inspecione a matriz e revise as evidências.</small></span><ArrowUpRight
              :size="18"
              aria-hidden="true"
            />
          </RouterLink>
          <RouterLink
            v-if="auth.can('requirement.view')"
            class="journey-link"
            to="/requisitos"
          >
            <ListChecks
              :size="22"
              aria-hidden="true"
            /><span><strong>Checklists versionados</strong><small>Consulte os requisitos e suas versões publicadas.</small></span><ArrowUpRight
              :size="18"
              aria-hidden="true"
            />
          </RouterLink>
          <RouterLink
            v-if="auth.can('document.view') && auth.can('requirement.view') && auth.can('supplier.view')"
            class="journey-link"
            to="/comparacoes"
          >
            <GitCompareArrows
              :size="22"
              aria-hidden="true"
            /><span><strong>Comparar fornecedores</strong><small>Mesmos requisitos, evidências e revisões lado a lado.</small></span><ArrowUpRight
              :size="18"
              aria-hidden="true"
            />
          </RouterLink>
        </section>
        <aside class="surface-card portfolio-section portfolio-method">
          <p class="eyebrow">
            COMO LER OS INDICADORES
          </p><h2>Um retrato da versão atual</h2><p>As métricas consideram a análise mais recente de cada fornecedor ativo, concluída com a versão publicada atual do checklist.</p><p>O status efetivo inclui sugestões da IA ainda não revisadas e prioriza a última revisão humana quando ela existe.</p><p>A decisão final é registrada separadamente por uma pessoa autorizada, após a revisão dos itens obrigatórios.</p><RouterLink
            v-if="auth.can('audit.view')"
            class="text-action"
            to="/auditoria"
          >
            Consultar trilha de auditoria →
          </RouterLink>
        </aside>
      </div>
    </template>
  </ResourceState>
</template>
