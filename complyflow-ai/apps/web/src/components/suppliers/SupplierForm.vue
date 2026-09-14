<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../../lib/api'
import type { Envelope, Supplier } from '../../types/domain'
const props = defineProps<{ supplier?: Supplier }>()
const emit = defineEmits<{ saved: [supplier: Supplier]; cancel: [] }>()
const draft = reactive({ name: props.supplier?.name ?? '', tax_id: props.supplier?.tax_id ?? '', risk_level: props.supplier?.risk_level ?? 'medium' })
const fields = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)
const form = ref<HTMLFormElement>()
onMounted(() => form.value?.querySelector('input')?.focus())
async function save() {
  if (busy.value) return
  fields.value = {}; error.value = ''
  if (!draft.name.trim()) fields.value.name = 'Informe a razão social.'
  if (draft.name.trim().length > 255) fields.value.name = 'Use até 255 caracteres.'
  if (draft.tax_id.trim().length > 255) fields.value.tax_id = 'Use até 255 caracteres.'
  if (!Object.keys(fields.value).length) {
    busy.value = true
    try {
      const payload = { name: draft.name.trim(), tax_id: draft.tax_id.trim() || null, risk_level: draft.risk_level }
      const response = props.supplier ? await api.put<Envelope<Supplier>>(`/suppliers/${props.supplier.id}`, payload) : await api.post<Envelope<Supplier>>('/suppliers', payload)
      emit('saved', response.data.data)
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Não foi possível salvar.'
      if (cause instanceof ApiError) fields.value = cause.fields
    } finally { busy.value = false }
  }
  await nextTick(); form.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
</script>
<template>
  <form
    ref="form"
    class="surface-card resource-form"
    aria-label="Cadastro do fornecedor"
    novalidate
    @submit.prevent="save"
  >
    <div>
      <p class="eyebrow">
        DADOS CADASTRAIS
      </p><h2>{{ supplier ? 'Editar fornecedor' : 'Novo fornecedor' }}</h2>
    </div>
    <p
      v-if="error"
      class="error-notice"
      role="alert"
    >
      {{ error }}
    </p>
    <div class="field-grid">
      <div class="form-field">
        <label for="supplier-name">Razão social</label><input
          id="supplier-name"
          v-model="draft.name"
          autocomplete="organization"
          maxlength="255"
          required
          :aria-invalid="!!fields.name"
          :aria-describedby="fields.name ? 'supplier-name-error' : undefined"
        /><p
          v-if="fields.name"
          id="supplier-name-error"
          class="field-error"
        >
          {{ fields.name }}
        </p>
      </div>
      <div class="form-field">
        <label for="supplier-tax">CNPJ / identificação fiscal</label><input
          id="supplier-tax"
          v-model="draft.tax_id"
          class="mono"
          maxlength="255"
          :aria-invalid="!!fields.tax_id"
          :aria-describedby="fields.tax_id ? 'supplier-tax-error' : undefined"
        /><p
          v-if="fields.tax_id"
          id="supplier-tax-error"
          class="field-error"
        >
          {{ fields.tax_id }}
        </p>
      </div>
      <div class="form-field">
        <label for="supplier-risk">Risco do fornecedor</label><select
          id="supplier-risk"
          v-model="draft.risk_level"
          :aria-invalid="!!fields.risk_level"
          :aria-describedby="fields.risk_level ? 'supplier-risk-error' : undefined"
        >
          <option value="low">
            Baixo
          </option><option value="medium">
            Médio
          </option><option value="high">
            Alto
          </option>
        </select><p
          v-if="fields.risk_level"
          id="supplier-risk-error"
          class="field-error"
        >
          {{ fields.risk_level }}
        </p>
      </div>
    </div>
    <div class="actions">
      <button
        class="button button-primary"
        :disabled="busy"
      >
        {{ busy ? 'Salvando…' : 'Salvar fornecedor' }}
      </button><button
        type="button"
        class="button button-secondary"
        :disabled="busy"
        @click="$emit('cancel')"
      >
        Cancelar
      </button>
    </div>
  </form>
</template>
