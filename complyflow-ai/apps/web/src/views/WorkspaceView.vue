<script setup lang="ts">
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
const route = useRoute()
const auth = useAuthStore()
</script>

<template>
  <section class="surface-card workspace-welcome">
    <p class="eyebrow">
      {{ auth.session?.organization.name }}
    </p>
    <h1>{{ route.meta.title }}</h1>
    <template v-if="route.path === '/sem-permissao'">
      <p>Sua conta não tem permissão para acessar esta área. Solicite acesso ao administrador da organização.</p><RouterLink
        class="button button-secondary"
        to="/"
      >
        Voltar à visão geral
      </RouterLink>
    </template>
    <template v-else-if="route.path === '/ajuda'">
      <p>No dossiê do fornecedor, envie PDFs e inicie uma análise com uma versão publicada dos requisitos. Ao concluir, abra a matriz para inspecionar as evidências, revisar os achados e registrar uma decisão humana.</p>
      <p>Uma conta criada em “Criar conta” começa com uma organização vazia e papel de administrador. Cadastre seu primeiro fornecedor e publique um conjunto de requisitos para começar.</p>
      <p>A demonstração oferece uma organização isolada por 24 horas, com dados fictícios e papel de revisor. As ações disponíveis dependem das permissões da conta.</p>
      <RouterLink
        v-if="auth.can('supplier.view')"
        class="button button-primary"
        to="/fornecedores"
      >
        Abrir fornecedores
      </RouterLink>
    </template>
  </section>
</template>
