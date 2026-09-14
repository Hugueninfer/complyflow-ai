<script setup lang="ts">
import { useRoute } from 'vue-router'
import AppShell from './components/layout/AppShell.vue'
const route = useRoute()
</script>

<template>
  <AppShell v-if="route.meta.requiresAuth">
    <RouterView />
  </AppShell>
  <RouterView v-else />
</template>
