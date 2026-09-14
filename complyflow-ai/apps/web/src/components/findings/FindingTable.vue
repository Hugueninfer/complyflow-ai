<script setup lang="ts">
import { Eye } from '@lucide/vue'
import type { Finding } from '../../types/domain'
import StatusBadge from '../ui/StatusBadge.vue'
defineProps<{ findings: Finding[] }>()
const emit = defineEmits<{ inspect: [finding: Finding] }>()
</script>
<template>
  <table class="resource-table finding-table">
    <caption class="sr-only">
      Requisitos, sugestões da IA e revisões humanas
    </caption><thead><tr><th>REQUISITO</th><th>CATEGORIA / PESO</th><th>SUGESTÃO IA</th><th>CONFIANÇA IA</th><th>REVISÃO HUMANA</th><th>EVIDÊNCIA</th></tr></thead><tbody>
      <tr
        v-for="finding in findings"
        :key="finding.id"
      >
        <td><span class="mono muted">{{ finding.requirement.code }}</span><strong class="finding-title">{{ finding.requirement.title }}</strong><small v-if="finding.requirement.is_required">Requisito obrigatório</small></td><td data-label="Categoria / peso">
          {{ finding.requirement.category }}<span class="finding-weight mono">Peso {{ finding.requirement.weight }}</span>
        </td><td data-label="Sugestão IA">
          <StatusBadge :status="finding.status" />
        </td><td data-label="Confiança IA">
          <span class="mono confidence">{{ Math.round(finding.confidence * 100) }}%</span><meter
            :value="finding.confidence"
            min="0"
            max="1"
            :aria-label="`Confiança da IA para ${finding.requirement.code}`"
          ></meter>
        </td><td data-label="Revisão humana">
          <StatusBadge
            v-if="finding.latest_review"
            :status="finding.latest_review.status"
          /><span v-else>{{ finding.requires_human_review ? 'Revisão necessária' : 'Sem revisão' }}</span><small v-if="finding.review_locked">Histórico encerrado</small>
        </td><td>
          <button
            class="text-action"
            :aria-label="`Inspecionar ${finding.requirement.code}`"
            @click="($event.currentTarget as HTMLButtonElement).focus(); emit('inspect', finding)"
          >
            <Eye
              :size="17"
              aria-hidden="true"
            />Inspecionar <span class="mono">({{ finding.citations.length }})</span>
          </button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
