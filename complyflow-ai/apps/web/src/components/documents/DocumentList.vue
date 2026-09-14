<script setup lang="ts">
import { ref, watch } from 'vue'
import { FileText, Fingerprint } from '@lucide/vue'
import { api } from '../../lib/api'
import type { DocumentPage } from '../../types/domain'
import ResourceState from '../ui/ResourceState.vue'
const props = defineProps<{ supplierId: string; revision?: number }>()
const result = ref<DocumentPage>({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
const loading = ref(true), error = ref(''), page = ref(1)
let generation = 0
async function load(target = page.value) {
  const request = ++generation
  loading.value = true; error.value = ''; page.value = target
  try { const response = await api.get<DocumentPage>(`/suppliers/${props.supplierId}/documents?page=${target}`); if (request === generation) result.value = response.data }
  catch (cause) { if (request === generation) error.value = (cause as Error).message }
  finally { if (request === generation) loading.value = false }
}
watch(() => [props.supplierId, props.revision], () => load(1), { immediate: true })
</script>
<template>
  <section class="surface-card document-panel">
    <div class="section-heading">
      <div>
        <p class="eyebrow">
          EVIDÊNCIAS DOCUMENTAIS
        </p><h2>Documentos do fornecedor</h2>
      </div><span
        v-if="!loading && !error"
        class="count-tag"
      >{{ result.meta.total }} {{ result.meta.total === 1 ? 'arquivo' : 'arquivos' }}</span>
    </div>
    <ResourceState
      :loading="loading"
      :error="error"
      label="Carregando documentos"
      @retry="load()"
    >
      <div
        v-if="!result.data.length"
        class="empty-state"
      >
        <FileText
          :size="32"
          aria-hidden="true"
        /><h3>Nenhum documento enviado</h3><p>Envie um PDF para começar a reunir as evidências.</p>
      </div>
      <ul
        v-else
        class="document-list"
      >
        <li
          v-for="document in result.data"
          :key="document.id"
        >
          <FileText
            :size="26"
            aria-hidden="true"
          /><div>
            <strong class="mono document-filename">{{ document.storage_name }}</strong><p class="muted">
              PDF · {{ (document.size_bytes / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) }} KiB · {{ ({ uploaded: 'Recebido', processed: 'Processado', failed: 'Falha no processamento' } as Record<string, string>)[document.status] || 'Aguardando processamento' }}
            </p><details>
              <summary>
                <Fingerprint
                  :size="14"
                  aria-hidden="true"
                />Identificador SHA-256
              </summary><code>{{ document.sha256 }}</code>
            </details>
          </div>
        </li>
      </ul>
      <nav
        v-if="result.meta.last_page > 1"
        class="pagination"
        aria-label="Páginas de documentos"
      >
        <button
          class="button button-secondary"
          :disabled="page === 1"
          @click="load(page - 1)"
        >
          Anterior
        </button><span>Página {{ page }} de {{ result.meta.last_page }}</span><button
          class="button button-secondary"
          :disabled="page === result.meta.last_page"
          @click="load(page + 1)"
        >
          Próxima
        </button>
      </nav>
    </ResourceState>
  </section>
</template>
