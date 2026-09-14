<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { UserRoundCheck, LockKeyhole } from '@lucide/vue'
import { api, ApiError } from '../../lib/api'
import { useAuthStore } from '../../stores/auth'
import type { Envelope } from '../../types/domain'
import type { DecisionContext, HumanDecision } from '../../types/portfolio'
import '../../styles/portfolio.css'
const props = defineProps<{ context?: DecisionContext }>()
const emit = defineEmits<{ saved: []; conflict: [] }>()
const auth = useAuthStore(), choice = ref(''), reason = ref(''), confirmed = ref(false), saving = ref(false), error = ref(''), blocked = ref(false), recorded = ref<HumanDecision>()
const resultHeading = ref<HTMLElement>()
let bodyCache = '', key = '', active = true
const labels = { approved: 'Aprovado por decisão humana', rejected: 'Reprovado por decisão humana', conditional: 'Condicionado por decisão humana' }
const decision = computed(() => recorded.value ?? props.context?.decision)
const eligible = computed(() => props.context?.supplier_id && props.context.status === 'completed' && props.context.required_pending === 0 && props.context.is_latest_for_supplier && props.context.is_current_checklist)
const hasDraft = computed(() => Boolean(choice.value || reason.value))
const unavailable = computed(() => blocked.value || !eligible.value || !auth.can('supplier.decide'))
const canSubmit = computed(() => auth.can('supplier.decide') && eligible.value && !decision.value && !saving.value && !blocked.value && confirmed.value && ['approved', 'rejected', 'conditional'].includes(choice.value) && reason.value.trim().length > 0 && reason.value.length <= 10000)
async function submit() {
  if (!canSubmit.value || !props.context) return
  const body = { analysis_id: props.context.analysis_id, decision: choice.value, reason: reason.value.trim() }, encoded = JSON.stringify(body)
  if (bodyCache !== encoded) { bodyCache = encoded; key = crypto.randomUUID() }
  saving.value = true; error.value = ''
  try { const response = await api.post<Envelope<HumanDecision>>(`/suppliers/${encodeURIComponent(props.context.supplier_id!)}/decisions`, body, { idempotencyKey: key }); if (active) { recorded.value = response.data.data; emit('saved'); await nextTick(); if (active) resultHeading.value?.focus() } }
  catch (cause) { if (active) { const failure = cause as ApiError; error.value = failure.status === 409 ? 'Conflito: o estado da análise ou a decisão final mudou. Rascunho não registrado; copie sua justificativa e reinspecione a matriz atualizada.' : failure.message; if (failure.status === 409 || failure.status === 403) { blocked.value = true; emit('conflict') } } }
  finally { if (active) saving.value = false }
}
onBeforeUnmount(() => { active = false })
</script>
<template>
  <section
    v-if="(auth.can('supplier.decide') && context) || hasDraft"
    class="surface-card human-decision"
    aria-label="Decisão final do fornecedor"
  >
    <header>
      <UserRoundCheck
        :size="26"
        aria-hidden="true"
      /><div>
        <p class="eyebrow">
          RESPONSABILIDADE HUMANA
        </p><h2>Decisão final do fornecedor</h2>
      </div>
    </header>
    <p class="muted">
      A decisão é uma escolha explícita do revisor e fica separada das sugestões da IA e dos pareceres por requisito.
    </p>
    <div
      v-if="decision"
      class="recorded-decision"
      role="status"
    >
      <LockKeyhole
        :size="20"
        aria-hidden="true"
      /><div>
        <h3
          ref="resultHeading"
          tabindex="-1"
        >
          Decisão humana registrada · imutável
        </h3><strong>{{ labels[decision.decision] }}</strong><p>{{ decision.reason }}</p><p class="mono">
          {{ new Date(decision.decided_at).toLocaleString('pt-BR') }} · {{ decision.id }}
        </p><small>Para uma nova conclusão, inicie outra análise e revise seus achados.</small>
      </div>
    </div>
    <p
      v-else-if="!eligible && !hasDraft"
      class="historical-note"
    >
      Decisão indisponível: {{ context?.required_pending ? `${context.required_pending} requisito(s) obrigatório(s) ainda precisam de achado e revisão humana.` : 'É necessária a análise mais recente concluída, com fornecedor ativo e checklist publicado atual.' }}
    </p>
    <form
      v-if="(!decision && eligible && auth.can('supplier.decide')) || (hasDraft && unavailable)"
      aria-label="Registrar decisão final humana"
      @submit.prevent="submit"
    >
      <p
        v-if="hasDraft && unavailable"
        class="historical-note"
      >
        Rascunho não registrado · somente leitura. Copie a justificativa antes de sair desta página.
      </p>
      <p
        v-if="error"
        class="error-notice"
        role="alert"
      >
        {{ error }}
      </p>
      <label class="form-field">Decisão final humana<select
        v-model="choice"
        :disabled="saving || unavailable"
      ><option value="">Escolha sua decisão</option><option value="approved">Aprovar</option><option value="conditional">Condicionar</option><option value="rejected">Reprovar</option></select></label>
      <label class="form-field">Justificativa da decisão<textarea
        v-model="reason"
        rows="4"
        maxlength="10000"
        required
        :readonly="saving || unavailable"
      ></textarea></label>
      <label class="decision-confirm"><input
        v-model="confirmed"
        type="checkbox"
        :disabled="saving || unavailable"
      />Assumo a responsabilidade por esta decisão humana e sua justificativa.</label>
      <button
        class="button button-primary"
        :disabled="!canSubmit"
      >
        {{ saving ? 'Registrando decisão…' : 'Registrar decisão humana' }}
      </button>
    </form>
  </section>
</template>
