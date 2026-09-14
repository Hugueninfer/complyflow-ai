<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { UploadCloud, FileText, ShieldCheck } from '@lucide/vue'
import { api, ApiError } from '../../lib/api'
import type { DocumentMetadata, Envelope } from '../../types/domain'
defineProps<{ supplierId: string }>()
const emit = defineEmits<{ uploaded: [] }>()
const selected = ref<File>(), input = ref<HTMLInputElement>(), error = ref(''), success = ref(''), busy = ref(false), failed = ref(false), dragging = ref(false)
function select(files: FileList | File[] | null) {
  if (busy.value) return
  error.value = ''; success.value = ''; failed.value = false; selected.value = undefined
  const file = files?.[0]
  if (!file) return
  if (files!.length !== 1) error.value = 'Selecione um PDF por envio.'
  else if (!/\.pdf$/i.test(file.name) || (file.type && file.type !== 'application/pdf')) error.value = 'Somente PDF com extensão .pdf e tipo compatível é aceito.'
  else if (!file.size) error.value = 'O arquivo está vazio. Selecione um PDF válido.'
  else if (file.size > 5 * 1024 * 1024) error.value = 'Selecione um PDF de até 5 MiB.'
  else selected.value = file
  if (input.value) input.value.value = ''
}
function drop(event: DragEvent) { dragging.value = false; select(event.dataTransfer?.files ?? null) }
function removeSelection() { selected.value = undefined; error.value = ''; failed.value = false; void nextTick(() => input.value?.focus()) }
async function upload(supplierId: string) {
  if (!selected.value || busy.value) return
  busy.value = true; error.value = ''; success.value = ''; failed.value = false
  try {
    const payload = new FormData(); payload.append('file', selected.value)
    const response = await api.post<Envelope<DocumentMetadata>>(`/suppliers/${supplierId}/documents`, payload)
    success.value = response.status === 200 ? 'Este PDF já estava cadastrado para o fornecedor. Nenhuma cópia adicional foi criada.' : 'PDF recebido com sucesso. O recebimento não representa aprovação de conformidade.'
    selected.value = undefined; emit('uploaded')
  } catch (cause) {
    failed.value = true
    error.value = cause instanceof ApiError && cause.status === 429 ? 'O limite de documentos ou a cota de armazenamento foi atingido. Aguarde antes de tentar novamente.' : (cause as Error).message
  } finally { busy.value = false; if (!selected.value) void nextTick(() => input.value?.focus()) }
}
</script>
<template>
  <section
    class="upload-panel"
    :aria-busy="busy"
  >
    <div
      class="pdf-dropzone"
      :class="{ dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="drop"
    >
      <span class="upload-icon"><UploadCloud
        :size="34"
        aria-hidden="true"
      /></span><h2>Arraste seu PDF para cá</h2><p class="muted">
        ou selecione um arquivo no seu dispositivo
      </p><label class="button button-secondary file-picker"><UploadCloud
        :size="18"
        aria-hidden="true"
      />Selecionar arquivo PDF<input
        ref="input"
        type="file"
        accept="application/pdf,.pdf"
        aria-label="Arquivos PDF"
        :disabled="busy"
        aria-describedby="pdf-rules"
        @change="select(($event.target as HTMLInputElement).files)"
      /></label><p
        id="pdf-rules"
        class="muted"
      >
        Um PDF por envio · até 5 MiB por arquivo
      </p><p class="upload-validation">
        <ShieldCheck
          :size="17"
          aria-hidden="true"
        />O servidor valida novamente o formato, o tamanho e a assinatura do PDF.
      </p>
    </div>
    <p
      v-if="error"
      role="alert"
      class="error-notice"
    >
      {{ error }}
    </p><p
      v-if="success"
      role="status"
      class="success-notice"
    >
      {{ success }}
    </p>
    <div
      v-if="selected"
      class="surface-card selected-file"
    >
      <FileText
        :size="28"
        aria-hidden="true"
      /><div>
        <strong>{{ selected.name }}</strong><p class="mono muted">
          {{ (selected.size / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) }} KiB · PDF
        </p>
      </div><button
        class="button button-secondary"
        :disabled="busy"
        @click="removeSelection"
      >
        Remover seleção
      </button>
    </div>
    <div
      v-if="selected"
      class="upload-submit"
    >
      <div
        v-if="busy"
        class="muted"
      >
        <progress aria-label="Envio do PDF em andamento"></progress><p>Enviando e validando no servidor…</p>
      </div><p
        v-else
        class="muted"
      >
        Confirme o arquivo antes de enviá-lo para este fornecedor.
      </p><button
        class="button button-primary"
        :disabled="busy"
        @click="upload(supplierId)"
      >
        <UploadCloud
          :size="18"
          aria-hidden="true"
        />{{ busy ? 'Enviando PDF…' : failed ? 'Tentar envio novamente' : 'Confirmar e enviar PDF' }}
      </button>
    </div>
  </section>
</template>
