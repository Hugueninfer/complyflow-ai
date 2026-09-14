<script setup lang="ts">
defineProps<{ loading?: boolean; error?: string; label?: string }>()
defineEmits<{ retry: [] }>()
</script>
<template>
  <div
    v-if="loading"
    class="resource-skeleton surface-card"
    :aria-label="label"
    aria-busy="true"
  >
    <div></div><div></div><div></div>
  </div>
  <div
    v-else-if="error"
    class="error-notice resource-error"
    role="alert"
  >
    <p>{{ error }}</p><button
      class="button button-secondary"
      @click="$emit('retry')"
    >
      Tentar novamente
    </button>
  </div>
  <slot v-else></slot>
</template>
