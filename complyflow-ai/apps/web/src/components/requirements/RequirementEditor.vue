<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { Plus, Trash2 } from '@lucide/vue'
import { api, ApiError } from '../../lib/api'
import type { Envelope, Requirement, RequirementSet } from '../../types/domain'
const props = defineProps<{ set?: RequirementSet }>()
const emit = defineEmits<{ saved: [set: RequirementSet]; cancel: [] }>()
const blank = (): Requirement => ({ code: '', title: '', category: '', weight: 1, position: 0, evaluation_text: '', is_required: true })
const name = ref(props.set?.name ?? '')
const requirements = ref<Requirement[]>(props.set ? props.set.requirements.map(r => ({ ...r })) : [blank()])
const fields = ref<Record<string, string>>({}), error = ref(''), busy = ref(false)
const form = ref<HTMLFormElement>()
const textFields = [{ key: 'code', label: 'Código' }, { key: 'title', label: 'Título' }, { key: 'category', label: 'Categoria' }] as const
onMounted(() => form.value?.querySelector('input')?.focus())
async function add() { requirements.value.push(blank()); await nextTick(); form.value?.querySelector<HTMLInputElement>(`#requirement-${requirements.value.length - 1}-code`)?.focus() }
async function remove(index: number) { requirements.value.splice(index, 1); fields.value = {}; await nextTick(); form.value?.querySelector<HTMLInputElement>(`#requirement-${Math.max(0, index - 1)}-code`)?.focus() }
async function save() {
  if (busy.value || props.set?.status === 'published') return
  fields.value = {}; error.value = ''
  if (!name.value.trim() || name.value.trim().length > 255) fields.value.name = 'Informe um nome com até 255 caracteres.'
  if (!requirements.value.length) fields.value.requirements = 'Adicione ao menos um requisito.'
  const codes = new Set<string>()
  requirements.value.forEach((r, i) => {
    for (const field of ['code', 'title', 'category', 'evaluation_text'] as const) {
      if (!r[field].trim() || (field !== 'evaluation_text' && r[field].trim().length > 255)) fields.value[`requirements.${i}.${field}`] = field === 'evaluation_text' ? 'Descreva o critério de avaliação.' : 'Preencha este campo com até 255 caracteres.'
    }
    if (codes.has(r.code.trim())) fields.value[`requirements.${i}.code`] = 'Use um código único neste conjunto.'
    codes.add(r.code.trim())
    if (r.weight === '' || !Number.isFinite(Number(r.weight)) || Number(r.weight) < 0 || Number(r.weight) > 999.999) fields.value[`requirements.${i}.weight`] = 'Informe um peso entre 0 e 999,999.'
  })
  if (!Object.keys(fields.value).length) {
    busy.value = true
    try {
      const payload = { name: name.value.trim(), requirements: requirements.value.map((r, position) => ({ code: r.code.trim(), title: r.title.trim(), category: r.category.trim(), evaluation_text: r.evaluation_text.trim(), weight: Number(r.weight), position, is_required: r.is_required })) }
      const response = props.set ? await api.put<Envelope<RequirementSet>>(`/requirement-sets/${props.set.id}`, payload) : await api.post<Envelope<RequirementSet>>('/requirement-sets', payload)
      emit('saved', response.data.data)
    } catch (cause) { error.value = (cause as Error).message; if (cause instanceof ApiError) fields.value = cause.fields }
    finally { busy.value = false }
  }
  await nextTick(); form.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
</script>
<template>
  <form
    ref="form"
    class="surface-card resource-form"
    aria-label="Editor de requisitos"
    novalidate
    @submit.prevent="save"
  >
    <div>
      <p class="eyebrow">
        DEFINIÇÃO DOS CRITÉRIOS
      </p><h2>{{ set ? 'Editar conjunto' : 'Novo conjunto de requisitos' }}</h2><p class="muted">
        Versão {{ set?.version ?? 1 }} · Rascunho
      </p>
    </div>
    <p
      v-if="error"
      class="error-notice"
      role="alert"
    >
      {{ error }}
    </p>
    <div class="form-field">
      <label for="set-name">Nome do conjunto</label><input
        id="set-name"
        v-model="name"
        maxlength="255"
        :aria-invalid="!!fields.name"
        :aria-describedby="fields.name ? 'set-name-error' : undefined"
      /><p
        v-if="fields.name"
        id="set-name-error"
        class="field-error"
      >
        {{ fields.name }}
      </p>
    </div>
    <p
      v-if="fields.requirements"
      class="field-error"
      role="alert"
    >
      {{ fields.requirements }}
    </p>
    <fieldset
      v-for="(requirement, index) in requirements"
      :key="index"
      class="requirement-fields"
    >
      <legend>Requisito {{ index + 1 }}</legend><div class="field-grid">
        <div
          v-for="field in textFields"
          :key="field.key"
          class="form-field"
        >
          <label :for="`requirement-${index}-${field.key}`">{{ field.label }} {{ index + 1 }}</label><input
            :id="`requirement-${index}-${field.key}`"
            v-model="requirement[field.key]"
            maxlength="255"
            :aria-invalid="!!fields[`requirements.${index}.${field.key}`]"
            :aria-describedby="fields[`requirements.${index}.${field.key}`] ? `requirement-${index}-${field.key}-error` : undefined"
          /><p
            v-if="fields[`requirements.${index}.${field.key}`]"
            :id="`requirement-${index}-${field.key}-error`"
            class="field-error"
          >
            {{ fields[`requirements.${index}.${field.key}`] }}
          </p>
        </div>
      </div>
      <div class="form-field">
        <label :for="`requirement-${index}-evaluation_text`">Critério de avaliação {{ index + 1 }}</label><textarea
          :id="`requirement-${index}-evaluation_text`"
          v-model="requirement.evaluation_text"
          rows="3"
          :aria-invalid="!!fields[`requirements.${index}.evaluation_text`]"
          :aria-describedby="fields[`requirements.${index}.evaluation_text`] ? `requirement-${index}-evaluation-error` : undefined"
          placeholder="Descreva a evidência necessária para atender este requisito."
        ></textarea><p
          v-if="fields[`requirements.${index}.evaluation_text`]"
          :id="`requirement-${index}-evaluation-error`"
          class="field-error"
        >
          {{ fields[`requirements.${index}.evaluation_text`] }}
        </p>
      </div>
      <div class="requirement-options">
        <div class="form-field">
          <label :for="`requirement-${index}-weight`">Peso {{ index + 1 }}</label><input
            :id="`requirement-${index}-weight`"
            v-model="requirement.weight"
            type="number"
            min="0"
            max="999.999"
            step="0.001"
            :aria-invalid="!!fields[`requirements.${index}.weight`]"
            :aria-describedby="fields[`requirements.${index}.weight`] ? `requirement-${index}-weight-error` : undefined"
          /><p
            v-if="fields[`requirements.${index}.weight`]"
            :id="`requirement-${index}-weight-error`"
            class="field-error"
          >
            {{ fields[`requirements.${index}.weight`] }}
          </p>
        </div><label class="checkbox-label"><input
          v-model="requirement.is_required"
          type="checkbox"
        />Obrigatório {{ index + 1 }}</label><button
          type="button"
          class="text-action danger-text"
          :disabled="busy || requirements.length === 1"
          :aria-label="`Remover requisito ${index + 1}`"
          @click="remove(index)"
        >
          <Trash2
            :size="16"
            aria-hidden="true"
          />Remover
        </button>
      </div>
    </fieldset>
    <div class="actions">
      <button
        type="button"
        class="button button-secondary"
        :disabled="busy"
        @click="add"
      >
        <Plus
          :size="16"
          aria-hidden="true"
        />Adicionar requisito
      </button>
    </div>
    <div class="form-footer actions">
      <button
        class="button button-primary"
        :disabled="busy"
      >
        {{ busy ? 'Salvando…' : 'Salvar rascunho' }}
      </button><button
        type="button"
        class="button button-secondary"
        :disabled="busy"
        @click="$emit('cancel')"
      >
        Cancelar
      </button><span class="muted">O rascunho só fica disponível para análise após publicação.</span>
    </div>
  </form>
</template>
