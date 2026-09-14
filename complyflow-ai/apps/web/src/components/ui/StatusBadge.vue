<script setup lang="ts">
import { computed } from 'vue'
import type { FindingStatus } from '../../types/domain'

const props = defineProps<{ status: FindingStatus }>()
const states = {
  met: { text: 'Conforme', icon: 'M8 12l3 3 5-6' },
  partial: { text: 'Parcial', icon: 'M12 7v6m0 3v.01' },
  missing: { text: 'Não conforme', icon: 'm9 9 6 6m0-6-6 6' },
  inconclusive: { text: 'Inconclusivo', icon: 'M9.5 9a2.5 2.5 0 1 1 4.3 1.7c-1.3 1-1.8 1.3-1.8 2.3m0 3v.01' },
}
const state = computed(() => states[props.status])
</script>

<template>
  <span
    class="status-badge"
    :class="`status-${status}`"
  >
    <svg
      role="img"
      :aria-label="`Status ${state.text.toLocaleLowerCase('pt-BR')}`"
      viewBox="0 0 24 24"
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <circle
        cx="12"
        cy="12"
        r="9"
      />
      <path :d="state.icon" />
    </svg>
    {{ state.text }}
  </span>
</template>
