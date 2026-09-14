<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ShieldCheck, RefreshCw, Link2 } from '@lucide/vue'
import { useRemoteResource } from '../composables/useRemoteResource'
import type { AuditPage } from '../types/portfolio'
import ResourceState from '../components/ui/ResourceState.vue'
import AuditTimeline from '../components/audit/AuditTimeline.vue'
import '../styles/portfolio.css'
const route = useRoute(), router = useRouter()
const { data, loading, error, load } = useRemoteResource<AuditPage>()
const page = computed(() => { const value = Number(route.query.page); return Number.isInteger(value) && value > 0 && value <= 2147483647 ? value : 1 })
function refresh() { void load(`/audit-logs?page=${page.value}&per_page=25`) }
watch(page, refresh, { immediate: true })
function move(next: number) { void router.replace({ query: { page: String(next) } }) }
</script>
<template>
  <header class="resource-heading">
    <div>
      <p class="eyebrow">
        GOVERNANÇA E RASTREABILIDADE
      </p><h1>Trilha de auditoria</h1><p class="muted">
        Registro cronológico de revisões e decisões humanas da organização.
      </p>
    </div><button
      class="button button-secondary"
      :disabled="loading"
      @click="refresh"
    >
      <RefreshCw
        :size="17"
        aria-hidden="true"
      />Atualizar e verificar
    </button>
  </header>
  <ResourceState
    :loading="loading"
    :error="error"
    label="Carregando auditoria"
    @retry="refresh"
  >
    <template v-if="data">
      <section
        class="surface-card integrity-notice"
        :class="{ broken: data.meta.integrity?.status === 'broken' }"
        :role="data.meta.integrity?.status === 'broken' ? 'alert' : 'status'"
      >
        <ShieldCheck
          :size="25"
          aria-hidden="true"
        /><div>
          <h2>Verificação técnica · SHA-256</h2><p v-if="data.meta.integrity?.status === 'verified'">
            Hashes e encadeamento desta página verificados.
          </p><p v-else-if="data.meta.integrity?.status === 'broken'">
            Quebra de integridade detectada nesta página. Solicite investigação técnica.
          </p><p v-else-if="data.meta.integrity?.status === 'empty'">
            Nenhum evento para verificar nesta página.
          </p><p v-else>
            Verificação indisponível para registros legados ou sem hash.
          </p><small>A verificação cobre os eventos desta página e sua ligação com o predecessor.</small>
        </div>
      </section>
      <div class="audit-layout">
        <section class="surface-card">
          <div class="portfolio-section audit-title">
            <h2>Eventos registrados</h2><span class="mono muted">{{ data.meta.total }} no total</span>
          </div><p
            v-if="!data.data.length"
            class="portfolio-empty"
          >
            Nenhum evento registrado nesta página.
          </p><AuditTimeline :events="data.data" />
          <nav
            class="portfolio-pagination"
            aria-label="Paginação da auditoria"
          >
            <button
              class="button button-secondary"
              :disabled="data.meta.current_page <= 1"
              @click="move(data.meta.current_page - 1)"
            >
              Página anterior
            </button><span aria-live="polite">Página {{ data.meta.current_page }} de {{ data.meta.last_page }}</span><button
              class="button button-secondary"
              :disabled="data.meta.current_page >= data.meta.last_page"
              @click="move(data.meta.current_page + 1)"
            >
              Próxima página
            </button>
          </nav>
        </section><aside class="surface-card portfolio-section portfolio-method">
          <Link2
            :size="25"
            aria-hidden="true"
          /><h2>Como interpretar os hashes</h2><p>Cada evento contém um hash do seu conteúdo e uma referência ao evento anterior. O servidor recalcula esses valores ao consultar a página.</p><p>Esta verificação técnica não é certificação jurídica, carimbo de tempo certificado ou prova de não repúdio.</p><p>Sem âncora externa, o encadeamento não comprova ausência de truncamento no fim da cadeia ou reescrita integral por administrador do banco.</p><p>Os registros são preservados sem edição pelo aplicativo. A demonstração expirada está sujeita à política de remoção dos dados temporários.</p>
        </aside>
      </div>
    </template>
  </ResourceState>
</template>
