<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { FileSearch, Play, RefreshCw } from '@lucide/vue'
import { api, ApiError } from '../../lib/api'
import { useAuthStore } from '../../stores/auth'
import type { AnalysisRun, DocumentMetadata, DocumentPage, Envelope, RequirementSet } from '../../types/domain'

const props = defineProps<{ supplierId: string }>()
const router = useRouter()
const auth = useAuthStore()
const requirementSets = ref<RequirementSet[]>([])
const documents = ref<DocumentMetadata[]>([])
const requirementSetId = ref('')
const documentIds = ref<string[]>([])
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const fieldError = ref('')
const readError = ref('')
const fields = ref<Record<string, string>>({})
const sessionExpired = ref(false)
let active = true
let generation = 0, lifecycle = 0
let readController: AbortController | undefined, submitController: AbortController | undefined
let pendingSubmission: { signature: string; key: string } | undefined

const availableDocuments = computed(() => documents.value.filter(document => document.status === 'uploaded' || document.status === 'ready'))
const canSubmit = computed(() => !loading.value && !busy.value && !sessionExpired.value && !readError.value && requirementSetId.value !== '' && documentIds.value.length > 0 && documentIds.value.length <= 10)
const signature = computed(() => `${requirementSetId.value}:${[...documentIds.value].sort().join(',')}`)
const documentError = computed(() => Object.keys(fields.value).some(key => key === 'document_ids' || key.startsWith('document_ids.')) ? 'Verifique os PDFs selecionados e atualize as opções se necessário.' : '')
const selectedBytes = computed(() => availableDocuments.value.filter(document => documentIds.value.includes(document.id)).reduce((total, document) => total + document.size_bytes, 0))

function newKey() {
  const id = typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${crypto.getRandomValues(new Uint32Array(2)).join('-')}`
  return `analysis-ui:${id}`
}

async function load() {
  if (busy.value || !active) return
  const request = ++generation, owner = lifecycle, supplierId = props.supplierId
  readController?.abort()
  readController = new AbortController()
  const signal = readController.signal
  loading.value = true
  readError.value = ''
  try {
    const [setsResponse, firstPage] = await Promise.all([
      api.get<Envelope<RequirementSet[]>>('/requirement-sets', { signal }),
      api.get<DocumentPage>(`/suppliers/${supplierId}/documents?page=1`, { signal }),
    ])
    if (!active || request !== generation || owner !== lifecycle) return
    const pages = [firstPage.data]
    for (let page = 2; page <= firstPage.data.meta.last_page; page++) {
      pages.push((await api.get<DocumentPage>(`/suppliers/${supplierId}/documents?page=${page}`, { signal })).data)
      if (!active || request !== generation || owner !== lifecycle) return
    }
    requirementSets.value = setsResponse.data.data.filter(set => set.status === 'published')
    documents.value = pages.flatMap(page => page.data)
    if (!requirementSets.value.some(set => set.id === requirementSetId.value)) requirementSetId.value = ''
    documentIds.value = documentIds.value.filter(id => availableDocuments.value.some(document => document.id === id))
  } catch (cause) {
    if (active && request === generation && owner === lifecycle) readError.value = (cause as Error).message
  } finally {
    if (active && request === generation && owner === lifecycle) loading.value = false
  }
}

function toggleDocument(id: string, checked: boolean) {
  fieldError.value = ''
  if (!checked) {
    documentIds.value = documentIds.value.filter(value => value !== id)
    return
  }
  if (documentIds.value.length >= 10) {
    fieldError.value = 'Selecione no máximo 10 documentos por análise.'
    return
  }
  documentIds.value = [...documentIds.value, id]
}

async function submit() {
  if (busy.value || loading.value || sessionExpired.value || readError.value || !active) return
  error.value = ''
  fieldError.value = ''
  fields.value = {}
  if (!requirementSets.value.some(set => set.id === requirementSetId.value) || documentIds.value.length < 1 || documentIds.value.length > 10 || documentIds.value.some(id => !availableDocuments.value.some(document => document.id === id))) {
    fieldError.value = 'Escolha um conjunto publicado e de 1 a 10 documentos.'
    return
  }
  if (selectedBytes.value > 15 * 1024 * 1024) { fieldError.value = 'Selecione PDFs que somem no máximo 15 MiB por análise.'; return }
  if (!pendingSubmission || pendingSubmission.signature !== signature.value) {
    pendingSubmission = { signature: signature.value, key: newKey() }
  }
  busy.value = true
  const owner = lifecycle, supplierId = props.supplierId
  submitController = new AbortController()
  try {
    const response = await api.post<Envelope<AnalysisRun>>(`/suppliers/${supplierId}/analyses`, {
      requirement_set_id: requirementSetId.value,
      document_ids: documentIds.value,
    }, { idempotencyKey: pendingSubmission.key, signal: submitController.signal })
    if (active && owner === lifecycle) await router.push(`/analises/${response.data.data.id}`)
  } catch (cause) {
    if (active && owner === lifecycle) {
      error.value = (cause as Error).message
      if (cause instanceof ApiError) {
        fields.value = cause.fields
        if (cause.status === 401) sessionExpired.value = true
        if (cause.status === 409) {
          pendingSubmission = undefined
          error.value = 'Esta solicitação conflitou com uma análise existente. Confira a seleção antes de tentar novamente.'
        }
      }
    }
  } finally {
    if (active && owner === lifecycle) busy.value = false
  }
}

watch(signature, (value, previous) => {
  if (previous !== undefined && value !== previous && pendingSubmission?.signature !== value) pendingSubmission = undefined
})
watch(() => props.supplierId, () => {
  lifecycle++
  submitController?.abort()
  busy.value = false
  sessionExpired.value = false
  fields.value = {}; error.value = ''; fieldError.value = ''
  requirementSets.value = []; documents.value = []
  requirementSetId.value = ''
  documentIds.value = []
  pendingSubmission = undefined
  void load()
}, { immediate: true, flush: 'sync' })
onBeforeUnmount(() => { active = false; lifecycle++; generation++; readController?.abort(); submitController?.abort() })
</script>

<template>
  <section
    class="surface-card start-analysis-panel"
    aria-labelledby="start-analysis-title"
  >
    <header class="section-heading">
      <div>
        <p class="eyebrow">
          AVALIAÇÃO ASSISTIDA POR IA
        </p>
        <h2 id="start-analysis-title">
          Iniciar nova análise
        </h2>
      </div>
      <FileSearch
        :size="25"
        aria-hidden="true"
      />
    </header>
    <p class="muted">
      Combine uma versão publicada dos requisitos com 1 a 10 PDFs deste fornecedor, até 15 MiB no total. A decisão continuará sendo humana.
    </p>

    <div
      v-if="loading"
      class="analysis-selection-status"
      role="status"
    >
      Carregando requisitos e documentos…
    </div>
    <div
      v-else-if="readError"
      class="error-notice resource-error"
      role="alert"
    >
      <span>{{ readError }}</span><button
        class="button button-secondary"
        type="button"
        @click="load"
      >
        <RefreshCw
          :size="16"
          aria-hidden="true"
        />Tentar novamente
      </button>
    </div>
    <form
      v-else
      class="analysis-start-form"
      aria-label="Iniciar análise documental"
      :aria-busy="busy"
      @submit.prevent="submit"
    >
      <div class="form-field">
        <label for="analysis-requirement-set">Conjunto de requisitos</label>
        <select
          id="analysis-requirement-set"
          v-model="requirementSetId"
          :disabled="busy || sessionExpired || !requirementSets.length"
          :aria-invalid="!!fields.requirement_set_id"
          :aria-describedby="fields.requirement_set_id ? 'analysis-set-error' : undefined"
        >
          <option value="">
            Selecione uma versão publicada
          </option>
          <option
            v-for="set in requirementSets"
            :key="set.id"
            :value="set.id"
          >
            {{ set.name }} · versão {{ set.version }}
          </option>
        </select>
        <p
          v-if="fields.requirement_set_id"
          id="analysis-set-error"
          class="field-error"
        >
          {{ fields.requirement_set_id }}
        </p>
        <p
          v-if="!requirementSets.length"
          class="prerequisite-note"
        >
          Nenhuma versão publicada. <template v-if="auth.can('requirement.view') && auth.can('requirement.create')">
            <RouterLink to="/requisitos">
              {{ auth.can('requirement.publish') ? 'Criar e publicar conjunto' : 'Criar rascunho' }}
            </RouterLink><span v-if="!auth.can('requirement.publish')">. Solicite a publicação ao administrador da organização.</span>
          </template><span v-else>Solicite ao administrador da organização um conjunto publicado.</span>
        </p>
      </div>

      <fieldset
        class="analysis-document-picker"
        :aria-describedby="documentError ? 'analysis-document-error' : undefined"
      >
        <legend>Documentos PDF <span class="muted">({{ documentIds.length }}/10 selecionados)</span></legend>
        <label
          v-for="document in availableDocuments"
          :key="document.id"
          class="analysis-document-option"
        >
          <input
            type="checkbox"
            :checked="documentIds.includes(document.id)"
            :disabled="busy || sessionExpired || (!documentIds.includes(document.id) && documentIds.length >= 10)"
            @change="toggleDocument(document.id, ($event.target as HTMLInputElement).checked)"
          />
          <span><strong class="mono">{{ document.storage_name }}</strong><small>{{ document.status === 'ready' ? 'Processado · disponível para nova análise' : 'Recebido · pronto para análise' }} · {{ (document.size_bytes / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) }} KiB</small></span>
        </label>
        <p
          v-if="!availableDocuments.length"
          class="prerequisite-note"
        >
          Nenhum PDF disponível. <RouterLink
            v-if="auth.can('document.upload')"
            :to="`/fornecedores/${supplierId}/documentos`"
          >
            Enviar PDF
          </RouterLink><span v-else>Solicite o envio de documentos a uma pessoa autorizada.</span>
        </p>
        <p
          v-if="documentError"
          id="analysis-document-error"
          class="field-error"
        >
          {{ documentError }}
        </p>
      </fieldset>

      <button
        class="text-action"
        type="button"
        :disabled="busy || sessionExpired"
        @click="load"
      >
        <RefreshCw
          :size="15"
          aria-hidden="true"
        />Atualizar opções
      </button>

      <p
        v-if="fieldError"
        class="field-error"
        role="alert"
      >
        {{ fieldError }}
      </p>
      <p
        v-if="error"
        class="error-notice"
        role="alert"
      >
        {{ error }}
      </p>
      <footer class="analysis-start-footer">
        <span class="muted">O processamento ocorre em segundo plano e pode levar alguns instantes.</span>
        <button
          class="button button-primary"
          type="submit"
          :disabled="!canSubmit"
        >
          <span
            v-if="busy"
            class="spinner"
            aria-hidden="true"
          ></span><Play
            v-else
            :size="17"
            aria-hidden="true"
          />{{ busy ? 'Iniciando…' : 'Iniciar análise documental' }}
        </button>
      </footer>
    </form>
  </section>
</template>
