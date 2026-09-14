<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { GitCompareArrows } from '@lucide/vue'
import { api } from '../lib/api'
import type { Envelope, Supplier, RequirementSet } from '../types/domain'
import type { Comparison } from '../types/portfolio'
import { useRemoteResource } from '../composables/useRemoteResource'
import ResourceState from '../components/ui/ResourceState.vue'
import ComparisonGrid from '../components/comparison/ComparisonGrid.vue'
import '../styles/portfolio.css'
const route = useRoute(), router = useRouter(), suppliers = ref<Supplier[]>([]), sets = ref<RequirementSet[]>([]), optionsLoading = ref(true), optionsError = ref('')
const left = ref(''), right = ref(''), set = ref('')
const { data, loading, error, load, invalidate } = useRemoteResource<Envelope<Comparison>>()
let optionGeneration = 0
const valid = computed(() => left.value !== right.value && suppliers.value.some(s => s.id === left.value) && suppliers.value.some(s => s.id === right.value) && sets.value.some(s => s.id === set.value))
async function loadOptions() {
  const current = ++optionGeneration; optionsLoading.value = true; optionsError.value = ''
  try { const results = await Promise.all([api.get<Envelope<Supplier[]>>('/suppliers'), api.get<Envelope<RequirementSet[]>>('/requirement-sets')]); if (current === optionGeneration) { suppliers.value = results[0].data.data; sets.value = results[1].data.data.filter(s => s.status === 'published') } }
  catch (cause) { if (current === optionGeneration) optionsError.value = (cause as Error).message }
  finally { if (current === optionGeneration) optionsLoading.value = false }
}
function fromQuery() {
  const scalar = (key: string) => typeof route.query[key] === 'string' ? route.query[key] as string : ''
  left.value = scalar('left'); right.value = scalar('right'); set.value = scalar('requirement_set')
  invalidate()
  if (left.value && right.value && set.value) void load(`/comparisons?${new URLSearchParams({ left: left.value, right: right.value, requirement_set: set.value })}`)
}
async function compare() { if (!valid.value) return; const query = { left: left.value, right: right.value, requirement_set: set.value }; if (Object.entries(query).every(([key, value]) => route.query[key] === value)) fromQuery(); else await router.replace({ query }) }
watch(() => route.query, fromQuery, { immediate: true })
onMounted(loadOptions)
onBeforeUnmount(() => { optionGeneration++ })
</script>
<template>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        ANÁLISE COMPARATIVA
      </p><h1>Comparação de fornecedores</h1><p class="muted">
        Consulte os mesmos requisitos de uma versão publicada, com IA e revisão humana identificadas.
      </p>
    </div>
  </header>
  <ResourceState
    :loading="optionsLoading"
    :error="optionsError"
    label="Carregando seletores"
    @retry="loadOptions"
  >
    <form
      class="surface-card comparison-selectors"
      aria-label="Selecionar comparação"
      @submit.prevent="compare"
    >
      <label class="form-field">Fornecedor à esquerda<select v-model="left"><option value="">Selecione um fornecedor</option><option
        v-for="supplier in suppliers"
        :key="supplier.id"
        :value="supplier.id"
      >{{ supplier.name }}</option></select></label>
      <label class="form-field">Fornecedor à direita<select v-model="right"><option value="">Selecione outro fornecedor</option><option
        v-for="supplier in suppliers"
        :key="supplier.id"
        :value="supplier.id"
      >{{ supplier.name }}</option></select></label>
      <label class="form-field">Versão do checklist<select v-model="set"><option value="">Selecione uma versão</option><option
        v-for="item in sets"
        :key="item.id"
        :value="item.id"
      >{{ item.name }} · v{{ item.version }}</option></select></label>
      <button
        class="button button-primary"
        :disabled="!valid || loading"
      >
        <GitCompareArrows
          :size="18"
          aria-hidden="true"
        />Comparar fornecedores
      </button>
      <p
        v-if="!suppliers.length || !sets.length"
        class="muted"
      >
        Cadastre dois fornecedores e publique um checklist para comparar.
      </p><p
        v-else-if="left && left === right"
        class="muted"
      >
        Selecione dois fornecedores diferentes.
      </p>
    </form>
  </ResourceState>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando comparação"
    @retry="fromQuery"
  >
    <template v-if="data?.data">
      <div class="comparison-caption">
        <h2>{{ data.data.requirement_set.name }} · v{{ data.data.requirement_set.version }}</h2><p
          v-if="!data.data.requirement_set.is_current"
          class="historical-note"
        >
          Versão histórica do checklist · somente consulta.
        </p><p
          v-else
          class="muted"
        >
          Versão publicada atual do checklist.
        </p><p class="muted">
          A comparação é descritiva. A confiança pertence ao achado da IA. Para inspecionar evidências integrais e decidir, abra a matriz da análise.
        </p>
      </div><ComparisonGrid :comparison="data.data" />
    </template>
    <p
      v-else-if="!optionsLoading && !optionsError"
      class="surface-card portfolio-empty"
    >
      Selecione dois fornecedores e uma versão do checklist para iniciar.
    </p>
  </ResourceState>
</template>
