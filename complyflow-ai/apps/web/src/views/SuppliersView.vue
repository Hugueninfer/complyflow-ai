<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { Building2, Plus, Search, ArrowUpRight, ShieldAlert } from '@lucide/vue'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Envelope, Supplier } from '../types/domain'
import SupplierForm from '../components/suppliers/SupplierForm.vue'
import ResourceState from '../components/ui/ResourceState.vue'
import { useResourceCollection } from '../composables/useResourceCollection'
const auth = useAuthStore()
const { items: suppliers, loading, error, load, upsert } = useResourceCollection(
  async () => (await api.get<Envelope<Supplier[]>>('/suppliers')).data.data,
  (a, b) => a.name.localeCompare(b.name),
)
const success = ref(''), creating = ref(false), search = ref(''), risk = ref('')
const createButton = ref<HTMLButtonElement>()
const riskLabels = { low: 'Baixo', medium: 'Médio', high: 'Alto' }
const filtered = computed(() => suppliers.value.filter(s => `${s.name} ${s.tax_id ?? ''}`.toLocaleLowerCase('pt-BR').includes(search.value.toLocaleLowerCase('pt-BR').trim()) && (!risk.value || s.risk_level === risk.value)))
function close() { creating.value = false; void nextTick(() => createButton.value?.focus()) }
function saved(supplier: Supplier) { upsert(supplier); success.value = 'Fornecedor cadastrado com sucesso.'; search.value = ''; risk.value = ''; close() }
onMounted(load)
</script>
<template>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        CADASTRO E GOVERNANÇA
      </p><h1>Gestão de Fornecedores</h1><p class="muted">
        Organize cadastros e documentos da sua cadeia de fornecedores.
      </p>
    </div><button
      v-if="auth.can('supplier.create') && !creating"
      ref="createButton"
      class="button button-primary"
      @click="creating = true; success = ''"
    >
      <Plus
        :size="18"
        aria-hidden="true"
      />Novo fornecedor
    </button>
  </header>
  <p
    v-if="success"
    class="success-notice"
    role="status"
  >
    {{ success }}
  </p>
  <SupplierForm
    v-if="creating"
    @saved="saved"
    @cancel="close"
  />
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando fornecedores"
    @retry="load"
  >
    <div class="metrics-grid">
      <section class="metric-card">
        <Building2
          :size="22"
          aria-hidden="true"
        /><p>Base cadastrada</p><strong>{{ suppliers.length }}</strong><small>Fornecedores nesta organização</small>
      </section><section class="metric-card">
        <ShieldAlert
          :size="22"
          aria-hidden="true"
        /><p>Risco alto</p><strong>{{ suppliers.filter(s => s.risk_level === 'high').length }}</strong><small>Classificação do cadastro</small>
      </section><section class="metric-card metric-note">
        <p>Conformidade com evidências</p><span>O nível de risco cadastral orienta a atenção. A decisão de conformidade exige análise e revisão humana.</span>
      </section>
    </div>
    <section class="surface-card resource-list">
      <div class="filter-bar">
        <div class="form-field search-field">
          <label for="supplier-search">Buscar fornecedores</label><div class="search-control">
            <Search
              :size="18"
              aria-hidden="true"
            /><input
              id="supplier-search"
              v-model="search"
              type="search"
              placeholder="Razão social ou identificação fiscal"
            />
          </div>
        </div><div class="form-field">
          <label for="risk-filter">Risco</label><select
            id="risk-filter"
            v-model="risk"
          >
            <option value="">
              Todos os riscos
            </option><option value="low">
              Baixo
            </option><option value="medium">
              Médio
            </option><option value="high">
              Alto
            </option>
          </select>
        </div><button
          v-if="search || risk"
          class="button button-secondary"
          @click="search = ''; risk = ''"
        >
          Limpar filtros
        </button>
      </div>
      <div
        v-if="!suppliers.length"
        class="empty-state"
      >
        <Building2
          :size="36"
          aria-hidden="true"
        /><h2>Nenhum fornecedor cadastrado</h2><p>Cadastre um fornecedor para reunir seus documentos e preparar a análise.</p>
      </div>
      <div
        v-else-if="!filtered.length"
        class="empty-state"
      >
        <h2>Nenhum resultado</h2><p>Ajuste a busca ou limpe os filtros.</p>
      </div>
      <table
        v-else
        class="resource-table"
      >
        <caption class="sr-only">
          Fornecedores da organização
        </caption><thead>
          <tr>
            <th scope="col">
              Fornecedor
            </th><th scope="col">
              Identificação fiscal
            </th><th scope="col">
              Risco cadastral
            </th><th scope="col">
              Dossiê
            </th>
          </tr>
        </thead><tbody>
          <tr
            v-for="supplier in filtered"
            :key="supplier.id"
          >
            <td data-label="Fornecedor">
              <RouterLink
                :to="`/fornecedores/${supplier.id}`"
                class="supplier-name"
              >
                <span
                  class="supplier-avatar"
                  aria-hidden="true"
                >{{ supplier.name.slice(0, 2).toUpperCase() }}</span><strong>{{ supplier.name }}</strong>
              </RouterLink>
            </td><td
              data-label="Identificação fiscal"
              class="mono"
            >
              {{ supplier.tax_id || 'Não informado' }}
            </td><td data-label="Risco cadastral">
              <span
                class="risk-badge"
                :class="`risk-${supplier.risk_level}`"
              ><ShieldAlert
                :size="14"
                aria-hidden="true"
              />{{ riskLabels[supplier.risk_level] }}</span>
            </td><td data-label="Dossiê">
              <RouterLink
                :to="`/fornecedores/${supplier.id}`"
                class="text-action"
                :aria-label="`Abrir dossiê de ${supplier.name}`"
              >
                Ver detalhes <ArrowUpRight
                  :size="16"
                  aria-hidden="true"
                />
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
      <p
        v-if="suppliers.length"
        class="list-footer"
      >
        {{ filtered.length }} de {{ suppliers.length }} fornecedores
      </p>
    </section>
  </ResourceState>
</template>
