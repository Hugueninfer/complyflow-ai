<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { FileText, ShieldCheck, X } from '@lucide/vue'
import type { Finding, FindingReview } from '../../types/domain'
import ReviewPanel from '../reviews/ReviewPanel.vue'
const props = defineProps<{ finding: Finding; success: string; fallbackFocus?: HTMLElement }>()
const emit = defineEmits<{ close: []; saved: [review: FindingReview]; conflict: [] }>()
const panel = ref<HTMLElement>(), closeButton = ref<HTMLButtonElement>()
const previous = document.activeElement as HTMLElement | null
let siblings: { node: HTMLElement; inert: boolean }[] = [], overflow = ''
onMounted(async () => {
  overflow = document.body.style.overflow; document.body.style.overflow = 'hidden'
  siblings = Array.from(document.body.children).filter((node): node is HTMLElement => node instanceof HTMLElement && !node.contains(panel.value!)).map(node => ({ node, inert: node.inert }))
  siblings.forEach(({ node }) => { node.inert = true })
  document.addEventListener('keydown', keyboard)
  await nextTick(); closeButton.value?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', keyboard); siblings.forEach(({ node, inert }) => { node.inert = inert }); document.body.style.overflow = overflow
  void nextTick(() => {
    const target = previous?.isConnected ? previous : props.fallbackFocus
    if (target?.isConnected) target.focus()
  })
})
function keyboard(event: KeyboardEvent) {
  if (event.key === 'Escape') { event.preventDefault(); emit('close'); return }
  if (event.key !== 'Tab') return
  const elements = Array.from(panel.value?.querySelectorAll<HTMLElement>('button, input, select, textarea, a[href], [tabindex="0"]') ?? []).filter(node => !node.matches(':disabled') && !node.closest('[inert]'))
  const first = elements[0], last = elements.at(-1)
  if (!panel.value?.contains(document.activeElement)) { event.preventDefault(); first?.focus(); return }
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
</script>
<template>
  <Teleport to="body">
    <div
      class="evidence-backdrop"
      @click.self="emit('close')"
    >
      <section
        ref="panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="evidence-title"
        class="evidence-drawer"
      >
        <header class="evidence-heading">
          <div>
            <p class="eyebrow">
              INSPEÇÃO DE EVIDÊNCIAS
            </p><h2 id="evidence-title">
              {{ finding.requirement.title }}
            </h2><p class="mono muted">
              {{ finding.requirement.code }} · {{ finding.requirement.category }}
            </p>
          </div><button
            ref="closeButton"
            class="button button-secondary"
            aria-label="Fechar evidência"
            @click="emit('close')"
          >
            <X
              :size="20"
              aria-hidden="true"
            />
          </button>
        </header>
        <div class="evidence-content">
          <div class="analysis-governance">
            <ShieldCheck
              :size="23"
              aria-hidden="true"
            /><p>A IA auxilia a inspeção. A presença de um trecho não comprova autenticidade nem substitui avaliação humana.</p>
          </div>
          <section
            v-for="citation in finding.citations"
            :key="citation.id"
            class="citation-card"
          >
            <div class="citation-title">
              <FileText
                :size="20"
                aria-hidden="true"
              /><div>
                <strong>Documento</strong><p class="mono">
                  {{ citation.document_id }}
                </p>
              </div>
            </div><p class="mono muted">
              Página {{ citation.page_number }} · Caracteres {{ citation.start_offset }}–{{ citation.end_offset }}
            </p><blockquote>{{ citation.quote }}</blockquote>
          </section>
          <section
            v-if="!finding.citations.length"
            class="citation-card"
          >
            <h3>Sem evidência localizada</h3><p>{{ finding.search_summary || 'Nenhuma citação foi retornada para este requisito.' }}</p>
          </section>
          <p
            v-if="success"
            role="status"
            class="success-notice"
          >
            {{ success }}
          </p>
          <ReviewPanel
            :key="finding.id"
            :finding="finding"
            @saved="emit('saved', $event)"
            @conflict="emit('conflict')"
          />
        </div>
      </section>
    </div>
  </Teleport>
</template>
