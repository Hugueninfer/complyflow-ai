<script setup lang="ts">
import { computed } from 'vue'
import { CheckCircle2, Circle, AlertTriangle } from '@lucide/vue'
import type { AnalysisRun } from '../../types/domain'
const props = defineProps<{ run: AnalysisRun }>()
const entries = computed(() => [
  { title: 'Solicitação recebida', detail: 'Análise registrada na fila', at: props.run.created_at, done: true },
  { title: props.run.started_at ? 'Processamento iniciado' : 'Aguardando processamento', detail: props.run.status === 'pending' ? 'Aguardando execução ou nova tentativa' : 'Análise assistida dos documentos', at: props.run.started_at, done: !!props.run.started_at },
  { title: props.run.status === 'failed' ? 'Análise interrompida' : 'Resultado disponível', detail: props.run.status === 'completed' ? 'Achados disponíveis para revisão humana' : props.run.status === 'failed' ? 'Nenhuma conclusão deve ser presumida' : 'Aguardando conclusão do processamento', at: props.run.completed_at, done: ['failed', 'completed'].includes(props.run.status) },
])
</script>
<template>
  <ol
    class="analysis-timeline"
    aria-label="Histórico da análise"
  >
    <li
      v-for="(entry, index) in entries"
      :key="index"
      :class="{ 'timeline-done': entry.done }"
    >
      <component
        :is="index === 2 && run.status === 'failed' ? AlertTriangle : entry.done ? CheckCircle2 : Circle"
        :size="22"
        aria-hidden="true"
      />
      <span class="eyebrow">0{{ index + 1 }}</span><h3>{{ entry.title }}</h3><p>{{ entry.detail }}</p>
      <time
        v-if="entry.at"
        class="mono"
        :datetime="entry.at"
      >{{ new Date(entry.at).toLocaleString('pt-BR') }}</time>
    </li>
  </ol>
</template>
