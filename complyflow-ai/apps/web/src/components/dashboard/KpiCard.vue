<script setup lang="ts">
import type { Component } from 'vue'
defineProps<{ label: string; value: number; description: string; icon: Component; tone?: string }>()
</script>
<template>
  <section
    class="surface-card portfolio-kpi"
    :class="tone"
    :aria-label="label"
  >
    <div>
      <h2>{{ label }}</h2><component
        :is="icon"
        :size="21"
        aria-hidden="true"
      />
    </div>
    <strong class="mono">{{ value.toLocaleString('pt-BR') }}</strong><p class="muted">
      {{ description }}
    </p>
  </section>
</template>
