<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeft, ListChecks, ShieldCheck, Bot } from '@lucide/vue'
import { api } from '../lib/api'
import type { Envelope, Finding, FindingReview } from '../types/domain'
import ResourceState from '../components/ui/ResourceState.vue'
import FindingTable from '../components/findings/FindingTable.vue'
import EvidenceDrawer from '../components/findings/EvidenceDrawer.vue'
import HumanDecisionPanel from '../components/reviews/HumanDecisionPanel.vue'
import type { DecisionContext } from '../types/portfolio'
import '../styles/analysis.css'
const route = useRoute(), findings = ref<Finding[]>([]), selectedId = ref(''), loading = ref(true), error = ref(''), success = ref(''), search = ref(''), status = ref(''), category = ref(''), pending = ref(false)
const searchInput = ref<HTMLInputElement>()
const decisionContext = ref<DecisionContext>()
let controller: AbortController | undefined, generation = 0
const selected = computed(() => findings.value.find(finding => finding.id === selectedId.value))
const categories = computed(() => [...new Set(findings.value.map(f => f.requirement.category))])
const awaiting = computed(() => findings.value.filter(f => f.requires_human_review && !f.latest_review).length)
const filtered = computed(() => findings.value.filter(f => (!status.value || f.status === status.value) && (!category.value || f.requirement.category === category.value) && (!pending.value || (f.requires_human_review && !f.latest_review)) && [f.requirement.code, f.requirement.title, f.requirement.category, f.justification, f.search_summary, ...f.citations.map(c => c.quote)].join(' ').toLocaleLowerCase('pt-BR').includes(search.value.toLocaleLowerCase('pt-BR').trim())))
async function load(background = false) {
  const current = ++generation; controller?.abort(); controller = new AbortController(); if (!background) loading.value = true; error.value = ''
  try { const response = await api.get<Envelope<Finding[]> & { meta?: { decision_context?: DecisionContext } }>(`/analyses/${encodeURIComponent(String(route.params.id))}/findings`, { signal: controller.signal }); if (current === generation) { findings.value = response.data.data; decisionContext.value = response.data.meta?.decision_context } }
  catch (cause) { if (current === generation) error.value = (cause as Error).message }
  finally { if (current === generation) loading.value = false }
}
function saved(review: FindingReview) { generation++; controller?.abort(); findings.value = findings.value.map(f => f.id === review.finding_id ? { ...f, latest_review: review } : f); success.value = 'Revisão registrada. A sugestão original da IA foi preservada.'; void load(true) }
watch(() => route.params.id, () => { selectedId.value = ''; success.value = ''; findings.value = []; decisionContext.value = undefined; search.value = ''; status.value = ''; category.value = ''; pending.value = false; void load() }, { immediate: true })
onBeforeUnmount(() => { generation++; controller?.abort() })
</script>
<template>
  <RouterLink
    class="text-action"
    :to="`/analises/${route.params.id}`"
  >
    <ArrowLeft
      :size="16"
      aria-hidden="true"
    />Acompanhamento da análise
  </RouterLink>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        CONFORMIDADE DOCUMENTAL · EVIDÊNCIAS
      </p><h1>Matriz de conformidade</h1><p class="muted">
        Inspecione os requisitos, compare a sugestão da IA e registre seu parecer.
      </p>
    </div>
  </header>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando matriz"
    @retry="load()"
  >
    <div class="metrics-grid matrix-metrics">
      <section class="metric-card">
        <ListChecks
          :size="23"
          aria-hidden="true"
        /><p>Requisitos analisados</p><strong>{{ findings.length }}</strong><small>Resultados do processamento</small>
      </section><section class="metric-card">
        <ShieldCheck
          :size="23"
          aria-hidden="true"
        /><p>Aguardando revisão humana</p><strong>{{ awaiting }}</strong><small>{{ findings.length - awaiting }} com revisão registrada</small>
      </section><section class="metric-card metric-note">
        <Bot
          :size="23"
          aria-hidden="true"
        /><p>IA assistiva · decisão humana</p><span>A confiança é uma estimativa do modelo. Cada conclusão precisa ser confrontada com as evidências.</span>
      </section>
    </div>
    <section class="surface-card resource-list">
      <div class="filter-bar matrix-filters">
        <label class="form-field search-field">Buscar requisito ou evidência<input
          ref="searchInput"
          v-model="search"
          type="search"
          placeholder="Código, título ou trecho…"
        /></label><label class="form-field">Status da IA<select v-model="status"><option value="">Todos os resultados</option><option value="met">Conforme</option><option value="partial">Parcial</option><option value="missing">Não conforme</option><option value="inconclusive">Inconclusivo</option></select></label><label class="form-field">Categoria<select v-model="category"><option value="">Todas as categorias</option><option
          v-for="item in categories"
          :key="item"
          :value="item"
        >{{ item }}</option></select></label><label class="pending-filter"><input
          v-model="pending"
          type="checkbox"
        />Apenas aguardando revisão</label>
      </div>
      <p
        class="matrix-count muted"
        aria-live="polite"
      >
        Exibindo {{ filtered.length }} de {{ findings.length }} requisitos
      </p>
      <p
        v-if="!findings.length"
        class="matrix-empty"
      >
        Nenhum achado disponível. Consulte o acompanhamento para verificar o estado da análise.
      </p><p
        v-else-if="!filtered.length"
        class="matrix-empty"
      >
        Nenhum requisito corresponde aos filtros.
      </p>
      <FindingTable
        v-else
        :findings="filtered"
        @inspect="selectedId = $event.id; success = ''"
      />
    </section>
  </ResourceState>
  <HumanDecisionPanel
    :key="String(route.params.id)"
    :context="decisionContext"
    @saved="load(true)"
    @conflict="load(true)"
  />
  <EvidenceDrawer
    v-if="selected"
    :finding="selected"
    :success="success"
    :fallback-focus="searchInput"
    @close="selectedId = ''"
    @saved="saved"
    @conflict="load(true)"
  />
</template>
