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
    <template v-else-if="route.path === '/'">
      <p>Olá, {{ auth.session?.user.name }}. Seu ambiente de conformidade documental está conectado.</p><p class="muted">
        Acompanhe fornecedores, evidências e revisões pelo menu lateral.
      </p><RouterLink
        class="button button-primary"
        to="/fornecedores"
      >
        Explorar fornecedores
      </RouterLink>
    </template>
    <template v-else-if="route.path === '/ajuda'">
      <p>Explore uma organização isolada por 24 horas na demonstração. Os dados são fictícios.</p><p>Analise documentos, confira os trechos de evidência e registre uma decisão humana. As permissões dependem do papel atribuído à sua conta.</p>
    </template>
    <template v-else>
      <p>Esta área está sendo preparada. Sua sessão e organização já estão conectadas.</p><RouterLink
        class="button button-secondary"
        to="/"
      >
        Voltar à visão geral
      </RouterLink>
    </template>
  </section>
</template>
