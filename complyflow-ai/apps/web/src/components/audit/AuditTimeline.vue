<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { Fingerprint, UserRoundCheck } from '@lucide/vue'
import type { AuditEvent } from '../../types/portfolio'
defineProps<{ events: AuditEvent[] }>()
const labels: Record<string, string> = { 'finding.reviewed': 'Revisão humana registrada', 'supplier.decided': 'Decisão humana registrada' }
const safeMetadata = ['finding_id', 'analysis_id', 'requirement_set_id', 'supplier_id', 'status', 'decision']
</script>
<template>
  <ol
    class="audit-timeline"
    aria-label="Linha do tempo de auditoria"
  >
    <li
      v-for="event in events"
      :key="event.id"
      class="audit-event"
    >
      <div class="audit-glyph">
        <UserRoundCheck
          :size="20"
          aria-hidden="true"
        />
      </div>
      <article>
        <header>
          <div>
            <h3>{{ labels[event.action] || 'Evento registrado' }}</h3><p class="mono muted">
              {{ event.action }}
            </p>
          </div><time
            class="mono"
            :datetime="event.occurred_at"
          >{{ new Date(event.occurred_at).toLocaleString('pt-BR') }}</time>
        </header>
        <dl class="audit-identities">
          <div>
            <dt>Evento</dt><dd class="mono">
              {{ event.id }}
            </dd>
          </div><div>
            <dt>Ator · UUID</dt><dd class="mono">
              {{ event.actor_public_id || 'Não disponível (legado)' }}
            </dd>
          </div><div>
            <dt>Alvo · {{ event.target_type }}</dt><dd class="mono">
              {{ event.target_id || 'Não disponível (legado)' }}
            </dd>
          </div>
        </dl>
        <RouterLink
          v-if="typeof event.metadata?.analysis_id === 'string'"
          class="text-action"
          :to="`/analises/${encodeURIComponent(event.metadata.analysis_id)}/matriz`"
        >
          Consultar análise relacionada →
        </RouterLink>
        <details>
          <summary>
            <Fingerprint
              :size="16"
              aria-hidden="true"
            />Detalhes técnicos
          </summary><dl class="audit-hashes">
            <dt>Hash do evento · SHA-256</dt><dd class="mono">
              {{ event.event_hash || 'Não disponível' }}
            </dd><dt>Hash anterior</dt><dd class="mono">
              {{ event.previous_hash || 'Sem predecessor informado' }}
            </dd><template
              v-for="key in safeMetadata"
              :key="key"
            >
              <template v-if="typeof event.metadata?.[key] === 'string'">
                <dt>{{ key }}</dt><dd class="mono">
                  {{ event.metadata[key] }}
                </dd>
              </template>
            </template>
          </dl>
        </details>
      </article>
    </li>
  </ol>
</template>
