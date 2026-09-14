<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { Comparison } from '../../types/portfolio'
import StatusBadge from '../ui/StatusBadge.vue'
defineProps<{ comparison: Comparison }>()
const sides = ['left', 'right'] as const
</script>
<template>
  <section
    class="surface-card comparison-results"
    aria-label="Resultados por requisito"
  >
    <div class="comparison-header">
      <div>REQUISITO</div><div
        v-for="side in sides"
        :key="side"
      >
        <strong>{{ comparison[side].supplier.name }}</strong><p v-if="!comparison[side].analysis">
          Sem análise concluída nesta versão.
        </p><template v-else>
          <p
            v-if="!comparison[side].analysis!.is_latest_for_supplier"
            class="historical-note"
          >
            Análise histórica do fornecedor
          </p><p
            v-else
            class="muted"
          >
            Análise mais recente do fornecedor
          </p><RouterLink
            class="text-action"
            :to="`/analises/${comparison[side].analysis!.id}/matriz`"
          >
            Abrir matriz · {{ comparison[side].supplier.name }}
          </RouterLink>
        </template>
      </div>
    </div>
    <p
      v-if="!comparison.rows.length"
      class="portfolio-empty"
    >
      Nenhum requisito nesta versão.
    </p>
    <article
      v-for="row in comparison.rows"
      :key="row.requirement.id"
      class="comparison-row"
      :aria-label="row.requirement.code"
    >
      <div class="comparison-requirement">
        <span class="mono">{{ row.requirement.code }}</span><h3>{{ row.requirement.title }}</h3><p class="muted">
          {{ row.requirement.category }}
        </p><small>{{ row.requirement.is_required ? 'Obrigatório' : 'Opcional' }}</small>
      </div>
      <section
        v-for="side in sides"
        :key="side"
        class="comparison-cell"
        :aria-label="`${row.requirement.code} · ${comparison[side].supplier.name}`"
      >
        <h4 class="mobile-supplier">
          {{ comparison[side].supplier.name }}
        </h4>
        <p
          v-if="!row[side]"
          class="muted"
        >
          Sem achado nesta versão.
        </p>
        <template v-else>
          <div class="comparison-ai">
            <h4>Sugestão da IA</h4><StatusBadge :status="row[side]!.ai.status" /><p>{{ row[side]!.ai.justification }}</p><small>Confiança da IA: {{ Math.round(row[side]!.ai.confidence * 100) }}% · estimativa do modelo</small>
          </div>
          <div class="comparison-human">
            <h4>Revisão humana</h4><template v-if="row[side]!.human_review">
              <StatusBadge :status="row[side]!.human_review!.status" /><p>{{ row[side]!.human_review!.justification }}</p><p v-if="row[side]!.human_review!.note">
                {{ row[side]!.human_review!.note }}
              </p>
            </template><p
              v-else
              class="muted"
            >
              Aguardando revisão humana.
            </p>
          </div>
          <div class="comparison-evidence">
            <h4>Evidências · {{ row[side]!.evidence_total }}</h4><blockquote
              v-for="citation in row[side]!.evidence"
              :key="citation.id"
            >
              <p>{{ citation.quote }}<span v-if="citation.quote_truncated">… (trecho resumido)</span></p><cite>Documento <span class="mono">{{ citation.document_id }}</span> · Página {{ citation.page_number }}</cite>
            </blockquote><p
              v-if="!row[side]!.evidence.length"
              class="muted"
            >
              {{ row[side]!.ai.search_summary || 'Nenhuma citação disponível.' }}
            </p>
          </div>
        </template>
      </section>
    </article>
  </section>
</template>
