<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Upload, ShieldCheck, Pencil } from '@lucide/vue'
import { api } from '../lib/api'
import type { Envelope, Supplier } from '../types/domain'
import { useAuthStore } from '../stores/auth'
import ResourceState from '../components/ui/ResourceState.vue'
import SupplierForm from '../components/suppliers/SupplierForm.vue'
import DocumentList from '../components/documents/DocumentList.vue'
const auth = useAuthStore(), route = useRoute(), router = useRouter()
const supplier = ref<Supplier>(), loading = ref(true), error = ref(''), editing = ref(false), success = ref(''), deleting = ref(false), busy = ref(false), deleteError = ref('')
const editButton = ref<HTMLButtonElement>(), deleteButton = ref<HTMLButtonElement>(), cancelDelete = ref<HTMLButtonElement>()
let generation = 0
async function load() { const current = ++generation; loading.value = true; error.value = ''; supplier.value = undefined; editing.value = false; deleting.value = false; success.value = ''; try { const response = await api.get<Envelope<Supplier>>(`/suppliers/${route.params.id}`); if (current === generation) supplier.value = response.data.data } catch (cause) { if (current === generation) error.value = (cause as Error).message } finally { if (current === generation) loading.value = false } }
function closeEdit() { editing.value = false; void nextTick(() => editButton.value?.focus()) }
function saved(value: Supplier) { supplier.value = value; success.value = 'Cadastro atualizado com sucesso.'; closeEdit() }
async function confirmDelete() { deleting.value = true; await nextTick(); cancelDelete.value?.focus() }
function closeDelete() { deleting.value = false; void nextTick(() => deleteButton.value?.focus()) }
async function remove() { if (busy.value || !supplier.value) return; busy.value = true; deleteError.value = ''; try { await api.delete(`/suppliers/${supplier.value.id}`); await router.push('/fornecedores') } catch (cause) { deleteError.value = (cause as Error).message } finally { busy.value = false } }
watch(() => route.params.id, load, { immediate: true })
</script>
<template>
  <RouterLink
    class="text-action"
    to="/fornecedores"
  >
    <ArrowLeft
      :size="16"
      aria-hidden="true"
    />Voltar aos fornecedores
  </RouterLink>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando fornecedor"
    @retry="load"
  >
    <template v-if="supplier">
      <section class="surface-card supplier-overview">
        <header class="resource-heading">
          <div>
            <p class="eyebrow">
              DOSSIÊ DO FORNECEDOR
            </p><h1>{{ supplier.name }}</h1><p class="mono muted">
              {{ supplier.tax_id || 'Identificação fiscal não informada' }}
            </p>
          </div><RouterLink
            v-if="auth.can('document.upload')"
            class="button button-primary"
            :to="`/fornecedores/${supplier.id}/documentos`"
          >
            <Upload
              :size="18"
              aria-hidden="true"
            />Enviar documentos PDF
          </RouterLink>
        </header><div class="record-facts">
          <div><span>RISCO CADASTRAL</span><strong>{{ { low: 'Baixo', medium: 'Médio', high: 'Alto' }[supplier.risk_level] }}</strong></div><div><span>IDENTIFICADOR DO FORNECEDOR</span><code>{{ supplier.id }}</code></div>
        </div>
      </section>
      <section
        v-if="auth.can('analysis.view')"
        class="surface-card"
      >
        <h2>Análise documental</h2>
        <template v-if="supplier.latest_analysis">
          <p class="muted">
            Consulte a análise mais recente deste fornecedor e inspecione as evidências antes de registrar seu parecer.
          </p>
          <RouterLink
            class="button button-primary"
            :to="`/analises/${supplier.latest_analysis.id}${supplier.latest_analysis.status === 'completed' ? '/matriz' : ''}`"
          >
            {{ supplier.latest_analysis.status === 'completed' ? 'Matriz de conformidade' : 'Acompanhar análise' }}
          </RouterLink>
        </template>
        <p
          v-else
          class="muted"
        >
          Este fornecedor ainda não possui análise documental.
        </p>
      </section>
      <p
        v-if="success"
        role="status"
        class="success-notice"
      >
        {{ success }}
      </p>
      <SupplierForm
        v-if="editing"
        :key="supplier.id"
        :supplier="supplier"
        @saved="saved"
        @cancel="closeEdit"
      />
      <div class="detail-grid">
        <DocumentList
          v-if="auth.can('document.view')"
          :supplier-id="supplier.id"
        /><section
          v-else
          class="surface-card"
        >
          <h2>Documentos</h2><p class="muted">
            Sua conta não tem permissão para consultar documentos.
          </p>
        </section><aside class="context-stack">
          <section class="surface-card governance-card">
            <ShieldCheck
              :size="25"
              aria-hidden="true"
            /><h2>Revisão humana</h2><p>Documentos recebidos não representam aprovação. A conformidade deve ser avaliada com requisitos e evidências e validada por um revisor.</p>
          </section><section
            v-if="auth.can('supplier.update')"
            class="surface-card"
          >
            <h2>Dados cadastrais</h2><p class="muted">
              Mantenha a razão social e o risco atualizados.
            </p><button
              v-if="!editing"
              ref="editButton"
              class="button button-secondary"
              @click="editing = true; success = ''"
            >
              <Pencil
                :size="16"
                aria-hidden="true"
              />Editar cadastro
            </button><button
              v-if="!deleting"
              ref="deleteButton"
              class="text-action danger-text"
              @click="confirmDelete"
            >
              Excluir fornecedor
            </button><section
              v-if="deleting"
              role="alertdialog"
              aria-label="Excluir fornecedor"
              @keydown.esc="closeDelete"
            >
              <p>Excluir {{ supplier.name }} do cadastro? O fornecedor deixará de aparecer nesta lista.</p><p
                v-if="deleteError"
                class="error-notice"
                role="alert"
              >
                {{ deleteError }}
              </p><div class="actions">
                <button
                  ref="cancelDelete"
                  class="button button-secondary"
                  :disabled="busy"
                  @click="closeDelete"
                >
                  Cancelar
                </button><button
                  class="button button-danger"
                  :disabled="busy"
                  @click="remove"
                >
                  {{ busy ? 'Excluindo…' : 'Confirmar exclusão' }}
                </button>
              </div>
            </section>
          </section>
        </aside>
      </div>
    </template>
  </ResourceState>
</template>
