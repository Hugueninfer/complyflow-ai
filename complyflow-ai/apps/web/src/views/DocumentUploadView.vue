<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, ShieldCheck } from '@lucide/vue'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Envelope, Supplier } from '../types/domain'
import ResourceState from '../components/ui/ResourceState.vue'
import PdfDropzone from '../components/documents/PdfDropzone.vue'
import DocumentList from '../components/documents/DocumentList.vue'
const route = useRoute(), auth = useAuthStore()
const supplier = ref<Supplier>(), loading = ref(true), error = ref(''), revision = ref(0)
let generation = 0
async function load() { const current = ++generation; loading.value = true; error.value = ''; supplier.value = undefined; try { const response = await api.get<Envelope<Supplier>>(`/suppliers/${route.params.id}`); if (current === generation) supplier.value = response.data.data } catch (cause) { if (current === generation) error.value = (cause as Error).message } finally { if (current === generation) loading.value = false } }
watch(() => route.params.id, load, { immediate: true })
</script>
<template>
  <RouterLink
    class="text-action"
    :to="`/fornecedores/${route.params.id}`"
  >
    <ArrowLeft
      :size="16"
      aria-hidden="true"
    />Voltar ao dossiê
  </RouterLink><header class="resource-heading">
    <div>
      <p class="eyebrow">
        EVIDÊNCIAS PARA ANÁLISE
      </p><h1>Envio de Documentos PDF</h1><p
        v-if="supplier"
        class="muted"
      >
        {{ supplier.name }} <span class="mono">· {{ supplier.tax_id || 'Sem identificação fiscal' }}</span>
      </p>
    </div>
  </header>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando fornecedor"
    @retry="load"
  >
    <template v-if="supplier">
      <section class="surface-card upload-guidance">
        <ShieldCheck
          :size="28"
          aria-hidden="true"
        /><div>
          <h2>Documentação vinculada ao fornecedor</h2><p>Os arquivos recebidos ficam associados a este dossiê. Documentos idênticos são reconhecidos para evitar cópias duplicadas.</p><p
            v-if="auth.session?.demo"
            class="mono"
          >
            Demo: até {{ (auth.session.demo.quotas.storage_bytes / 1048576).toLocaleString('pt-BR') }} MiB de armazenamento na sessão.
          </p>
        </div>
      </section><PdfDropzone
        :key="supplier.id"
        :supplier-id="supplier.id"
        @uploaded="revision++"
      /><DocumentList
        v-if="auth.can('document.view')"
        :supplier-id="supplier.id"
        :revision="revision"
      />
    </template>
  </ResourceState>
</template>
