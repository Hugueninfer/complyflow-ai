<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { api, ApiError } from '../../lib/api'
import { useAuthStore } from '../../stores/auth'
import type { Envelope, Finding, FindingReview, FindingStatus } from '../../types/domain'
import StatusBadge from '../ui/StatusBadge.vue'
const props = defineProps<{ finding: Finding }>()
const emit = defineEmits<{ saved: [review: FindingReview]; conflict: [] }>()
const auth = useAuthStore(), status = ref<FindingStatus>(props.finding.status), justification = ref(''), note = ref(''), confirmed = ref(false), busy = ref(false), error = ref(''), conflicted = ref(false)
let key = '', serialized = '', disposed = false
watch(() => props.finding.id, () => { status.value = props.finding.latest_review?.status ?? props.finding.status; justification.value = ''; note.value = ''; confirmed.value = false; error.value = ''; key = ''; conflicted.value = false }, { immediate: true })
onBeforeUnmount(() => { disposed = true })
async function save() {
  if (busy.value || !auth.can('finding.review') || props.finding.review_locked || conflicted.value) return
  error.value = ''
  if (!justification.value.trim()) { error.value = 'Informe uma justificativa humana para registrar esta revisão.'; return }
  if (!confirmed.value) { error.value = 'Confirme que inspecionou as evidências e assume esta revisão humana.'; return }
  const payload = { status: status.value, justification: justification.value.trim(), note: note.value.trim() || null, expected_review_id: props.finding.latest_review?.id ?? null }
  const next = JSON.stringify(payload)
  if (serialized !== next || !key) { key = crypto.randomUUID(); serialized = next }
  busy.value = true
  try {
    const response = await api.post<Envelope<FindingReview>>(`/findings/${props.finding.id}/reviews`, payload, { idempotencyKey: key })
    if (!disposed) { key = ''; confirmed.value = false; emit('saved', response.data.data) }
  } catch (cause) {
    if (disposed) return
    error.value = (cause as Error).message
    if (cause instanceof ApiError && cause.status === 409) { conflicted.value = true; error.value = 'Há uma revisão mais recente ou uma decisão final. Feche e reabra a evidência após atualizar a matriz para revisar o estado atual.'; emit('conflict') }
  } finally { if (!disposed) busy.value = false }
}
</script>
<template>
  <section
    class="review-comparison"
    aria-label="Comparação entre IA e revisão humana"
  >
    <div class="finding-ai-suggestion">
      <p class="eyebrow">
        SUGESTÃO ORIGINAL DA IA
      </p><StatusBadge :status="finding.status" /><p>{{ finding.justification }}</p><p class="mono confidence">
        {{ Math.round(finding.confidence * 100) }}% de confiança da IA
      </p>
    </div>
    <div class="human-record">
      <p class="eyebrow">
        ÚLTIMA REVISÃO HUMANA
      </p><template v-if="finding.latest_review">
        <StatusBadge :status="finding.latest_review.status" /><p>{{ finding.latest_review.justification }}</p><p
          v-if="finding.latest_review.note"
          class="review-note"
        >
          {{ finding.latest_review.note }}
        </p><time
          class="mono muted"
          :datetime="finding.latest_review.reviewed_at"
        >{{ new Date(finding.latest_review.reviewed_at).toLocaleString('pt-BR') }}</time>
      </template><p v-else>
        Ainda não há revisão registrada.
      </p>
    </div>
  </section>
  <p
    v-if="finding.review_locked"
    class="muted"
  >
    Revisões bloqueadas para esta análise. Uma decisão final preserva o histórico.
  </p>
  <form
    v-else-if="auth.can('finding.review')"
    class="review-form"
    aria-label="Revisão humana do achado"
    @submit.prevent="save"
  >
    <h3>Registrar revisão humana</h3><p class="muted">
      A sugestão original da IA será preservada. Esta revisão não é a decisão final do fornecedor.
    </p>
    <fieldset :disabled="busy || conflicted">
      <legend class="sr-only">
        Parecer do revisor
      </legend>
      <label class="form-field">Status revisado<select v-model="status"><option value="met">Conforme</option><option value="partial">Parcial</option><option value="missing">Não conforme</option><option value="inconclusive">Inconclusivo</option></select></label>
      <label class="form-field">Justificativa humana<textarea
        v-model="justification"
        rows="3"
        maxlength="10000"
        :aria-invalid="!!error && !justification.trim()"
      ></textarea></label>
      <label class="form-field">Observação opcional<textarea
        v-model="note"
        rows="2"
        maxlength="10000"
      ></textarea></label>
      <label class="human-confirmation"><input
        v-model="confirmed"
        type="checkbox"
      />Confirmo que inspecionei as evidências e assumo esta revisão humana.</label>
      <button
        type="submit"
        class="button button-primary"
      >
        {{ busy ? 'Registrando…' : 'Salvar revisão' }}
      </button>
    </fieldset><p
      v-if="error"
      role="alert"
      class="error-notice"
    >
      {{ error }}
    </p>
  </form>
  <p
    v-else
    class="muted"
  >
    Sua conta pode inspecionar os achados, mas não registrar revisões.
  </p>
</template>
