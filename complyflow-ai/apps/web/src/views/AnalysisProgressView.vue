<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { Bot, ArrowRight, ShieldCheck, RotateCcw } from '@lucide/vue'
import { useAnalysisPolling } from '../composables/useAnalysisPolling'
import { useAuthStore } from '../stores/auth'
import { api } from '../lib/api'
import type { AnalysisRun, Envelope } from '../types/domain'
import AnalysisTimeline from '../components/analysis/AnalysisTimeline.vue'
import '../styles/analysis.css'
const route = useRoute(), router = useRouter(), auth = useAuthStore()
const { run, loading, error, stopped, restart } = useAnalysisPolling(() => String(route.params.id))
const busy = ref(false), retryError = ref(''); let retryKey = ''
watch(() => route.params.id, () => { retryKey = ''; retryError.value = '' })
async function retry() {
  if (busy.value || !auth.can('analysis.run') || run.value?.status !== 'failed') return
  busy.value = true; retryError.value = ''; retryKey ||= crypto.randomUUID()
  try { const result = await api.post<Envelope<AnalysisRun>>(`/analyses/${route.params.id}/retry`, {}, { idempotencyKey: retryKey }); await router.push(`/analises/${result.data.data.id}`) }
  catch (cause) { retryError.value = (cause as Error).message } finally { busy.value = false }
}
</script>
<template>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        ANÁLISE ASSISTIDA · ACOMPANHAMENTO
      </p><h1>Processamento de documentos</h1><p class="muted">
        Acompanhe o estado informado pelo processamento e inspecione os resultados ao concluir.
      </p>
    </div><Bot
      class="analysis-hero-icon"
      :size="36"
      aria-hidden="true"
    />
  </header>
  <p
    v-if="loading"
    role="status"
    class="surface-card"
  >
    Carregando análise…
  </p>
  <div
    v-if="error"
    role="alert"
    class="error-notice"
  >
    {{ error }} <button
      v-if="stopped"
      class="text-action"
      @click="restart"
    >
      Consultar novamente
    </button><span v-else>A consulta será repetida automaticamente.</span>
  </div>
  <template v-if="run">
    <section class="surface-card progress-card">
      <div class="resource-heading">
        <div>
          <p class="eyebrow">
            PROGRESSO DA ANÁLISE
          </p><h2>{{ { pending: 'Aguardando processamento', processing: 'Análise em andamento', completed: 'Análise concluída', failed: 'Análise interrompida' }[run.status] }}</h2>
        </div><strong class="progress-percent mono">{{ run.progress }}%</strong>
      </div>
      <progress
        :value="run.progress"
        max="100"
        aria-label="Progresso informado pelo processamento"
      >
        {{ run.progress }}%
      </progress>
      <div class="record-facts">
        <div><span>TENTATIVAS DO PROCESSADOR</span><strong class="mono">{{ run.attempts }}</strong></div><div><span>IDENTIFICADOR DA ANÁLISE</span><code>{{ run.id }}</code></div>
      </div>
      <AnalysisTimeline :run="run" />
      <div
        v-if="run.status === 'completed'"
        class="analysis-next"
      >
        <div>
          <h3>Resultados prontos para inspeção</h3><p class="muted">
            Cada sugestão deve ser avaliada com suas evidências.
          </p>
        </div><RouterLink
          :to="`/analises/${run.id}/matriz`"
          class="button button-primary"
        >
          Matriz de conformidade <ArrowRight
            :size="17"
            aria-hidden="true"
          />
        </RouterLink>
      </div>
      <div
        v-if="run.status === 'failed'"
        class="analysis-next"
      >
        <p>{{ run.error_message || 'O processamento não foi concluído. Uma nova tentativa cria outra análise e utiliza a cota disponível.' }}</p><button
          v-if="auth.can('analysis.run')"
          class="button button-secondary"
          :disabled="busy"
          @click="retry"
        >
          <RotateCcw
            :size="17"
            aria-hidden="true"
          />{{ busy ? 'Solicitando…' : 'Reprocessar documentos' }}
        </button>
      </div>
      <p
        v-if="retryError"
        role="alert"
        class="error-notice"
      >
        {{ retryError }}
      </p>
    </section>
    <section class="surface-card analysis-governance">
      <ShieldCheck
        :size="27"
        aria-hidden="true"
      /><div><h2>O julgamento permanece humano</h2><p>A conclusão do processamento disponibiliza sugestões da IA. A revisão dos achados e a decisão final sobre o fornecedor são ações humanas distintas.</p></div>
    </section>
  </template>
</template>
