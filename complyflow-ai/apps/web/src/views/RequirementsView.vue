<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { Plus, ListChecks, LockKeyhole, FilePenLine, GitBranch } from '@lucide/vue'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Envelope, RequirementSet } from '../types/domain'
import ResourceState from '../components/ui/ResourceState.vue'
import RequirementEditor from '../components/requirements/RequirementEditor.vue'
import { useResourceCollection } from '../composables/useResourceCollection'
const auth = useAuthStore()
const { items: sets, loading, error, load, upsert } = useResourceCollection(
  async () => (await api.get<Envelope<RequirementSet[]>>('/requirement-sets')).data.data,
  (a, b) => a.name.localeCompare(b.name) || b.version - a.version,
)
const editing = ref<RequirementSet>(), creating = ref(false), publishing = ref<string>(), busy = ref(false), actionError = ref(''), success = ref('')
const heading = ref<HTMLHeadingElement>()
let trigger: HTMLElement | null = null
function start(set?: RequirementSet) { trigger = document.activeElement as HTMLElement; editing.value = set; creating.value = !set; publishing.value = undefined; success.value = ''; actionError.value = '' }
function close() { editing.value = undefined; creating.value = false; void nextTick(() => (trigger?.isConnected ? trigger : heading.value)?.focus()) }
function saved(set: RequirementSet) { upsert(set); success.value = 'Rascunho salvo com sucesso.'; close() }
async function publish(id: string) { if (busy.value) return; busy.value = true; actionError.value = ''; try { upsert((await api.post<Envelope<RequirementSet>>(`/requirement-sets/${id}/publish`)).data.data); publishing.value = undefined; success.value = 'Versão publicada. Seu conteúdo é imutável.'; void nextTick(() => heading.value?.focus()) } catch (cause) { actionError.value = (cause as Error).message } finally { busy.value = false } }
async function version(set: RequirementSet) { if (busy.value) return; busy.value = true; actionError.value = ''; try { const clone = (await api.post<Envelope<RequirementSet>>(`/requirement-sets/${set.id}/versions`)).data.data; upsert(clone); start(clone) } catch (cause) { actionError.value = (cause as Error).message } finally { busy.value = false } }
async function confirmPublish(id: string) { publishing.value = id; await nextTick(); document.getElementById(`cancel-publish-${id}`)?.focus() }
function closePublish() { if (busy.value) return; const id = publishing.value; publishing.value = undefined; void nextTick(() => document.getElementById(`publish-${id}`)?.focus()) }
onMounted(load)
</script>
<template>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        CRITÉRIOS E VERSIONAMENTO
      </p><h1
        ref="heading"
        tabindex="-1"
      >
        Conjuntos de Requisitos
      </h1><p class="muted">
        Defina evidências, pesos e critérios para orientar a análise documental.
      </p>
    </div><button
      v-if="auth.can('requirement.create') && !creating && !editing"
      class="button button-primary"
      @click="start()"
    >
      <Plus
        :size="18"
        aria-hidden="true"
      />Novo conjunto
    </button>
  </header>
  <p
    v-if="success"
    class="success-notice"
    role="status"
  >
    {{ success }}
  </p><p
    v-if="actionError"
    class="error-notice"
    role="alert"
  >
    {{ actionError }}
  </p>
  <RequirementEditor
    v-if="creating || editing"
    :key="editing?.id ?? 'new'"
    :set="editing"
    @saved="saved"
    @cancel="close"
  />
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando requisitos"
    @retry="load"
  >
    <div
      v-if="!sets.length && !creating"
      class="surface-card empty-state"
    >
      <ListChecks
        :size="36"
        aria-hidden="true"
      /><h2>Nenhum conjunto de requisitos</h2><p>Crie um rascunho e publique uma versão para utilizá-la nas análises.</p>
    </div>
    <section
      v-for="set in sets.filter(s => s.id !== editing?.id)"
      :key="set.id"
      class="surface-card requirement-card"
    >
      <header class="section-heading">
        <div>
          <p class="eyebrow">
            VERSÃO {{ set.version }} · {{ set.requirements.length }} REQUISITOS
          </p><h2>{{ set.name }}</h2>
        </div><span
          class="risk-badge"
          :class="set.status === 'published' ? 'risk-low' : 'risk-medium'"
        ><LockKeyhole
          v-if="set.status === 'published'"
          :size="14"
          aria-hidden="true"
        /><FilePenLine
          v-else
          :size="14"
          aria-hidden="true"
        />{{ set.status === 'published' ? 'Publicada' : 'Rascunho' }}</span>
      </header>
      <details class="requirement-details">
        <summary>Consultar critérios e evidências</summary><ol>
          <li
            v-for="requirement in set.requirements"
            :key="requirement.id ?? requirement.code"
          >
            <div class="section-heading">
              <strong>{{ requirement.title }}</strong><code>{{ requirement.code }}</code>
            </div><p>{{ requirement.evaluation_text }}</p><small class="muted">{{ requirement.category }} · Peso {{ Number(requirement.weight).toLocaleString('pt-BR') }} · {{ requirement.is_required ? 'Obrigatório' : 'Opcional' }}</small>
          </li>
        </ol>
      </details>
      <footer class="requirement-footer">
        <p class="muted">
          {{ set.status === 'published' ? 'Versão preservada. Alterações exigem um novo rascunho.' : 'Rascunho editável. Revise os critérios antes de publicar.' }}
        </p><div class="actions">
          <button
            v-if="set.status === 'draft' && auth.can('requirement.update') && !creating && !editing"
            class="button button-secondary"
            :disabled="busy"
            @click="start(set)"
          >
            Editar rascunho
          </button><button
            v-if="set.status === 'draft' && auth.can('requirement.publish') && publishing !== set.id && !creating && !editing"
            :id="`publish-${set.id}`"
            class="button button-primary"
            :disabled="busy"
            @click="confirmPublish(set.id)"
          >
            Publicar versão
          </button><button
            v-if="set.status === 'published' && auth.can('requirement.create') && !creating && !editing"
            class="button button-secondary"
            :disabled="busy"
            @click="version(set)"
          >
            <GitBranch
              :size="16"
              aria-hidden="true"
            />Criar nova versão
          </button>
        </div>
      </footer>
      <section
        v-if="publishing === set.id"
        class="confirmation-panel"
        role="alertdialog"
        aria-label="Publicar versão"
        @keydown.esc="closePublish"
      >
        <p>Após publicar, esta versão será imutável. Confirma que os critérios foram revisados?</p><div class="actions">
          <button
            :id="`cancel-publish-${set.id}`"
            class="button button-secondary"
            :disabled="busy"
            @click="closePublish"
          >
            Cancelar
          </button><button
            class="button button-primary"
            :disabled="busy"
            @click="publish(set.id)"
          >
            {{ busy ? 'Publicando…' : 'Confirmar publicação' }}
          </button>
        </div>
      </section>
    </section>
  </ResourceState>
</template>
