import { onBeforeUnmount, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'
import { api, ApiError } from '../lib/api'
import type { AnalysisRun, Envelope } from '../types/domain'

export function useAnalysisPolling(id: MaybeRefOrGetter<string>, intervalMs = 2500) {
  const run = ref<AnalysisRun>(), loading = ref(true), error = ref(''), stopped = ref(false)
  let timer: ReturnType<typeof setTimeout> | undefined, controller: AbortController | undefined, generation = 0, failures = 0, disposed = false
  function cancel() { clearTimeout(timer); controller?.abort(); controller = undefined; generation++ }
  async function poll() {
    if (disposed || controller || stopped.value || document.visibilityState === 'hidden') return
    const current = generation, request = new AbortController(); controller = request
    try {
      const response = await api.get<Envelope<AnalysisRun>>(`/analyses/${encodeURIComponent(toValue(id))}`, { signal: request.signal })
      if (current !== generation || disposed) return
      run.value = response.data.data; error.value = ''; failures = 0
      stopped.value = ['completed', 'failed'].includes(run.value.status)
    } catch (cause) {
      if (current !== generation || disposed) return
      error.value = cause instanceof Error ? cause.message : 'Não foi possível consultar a análise.'
      failures++
      stopped.value = cause instanceof ApiError && [401, 403, 404, 422].includes(cause.status)
    } finally {
      if (current === generation && !disposed) {
        controller = undefined; loading.value = false
        if (!stopped.value) timer = setTimeout(() => { void poll() }, Math.min(30000, intervalMs * 2 ** Math.min(failures, 4)))
      }
    }
  }
  function restart() { cancel(); failures = 0; stopped.value = false; error.value = ''; loading.value = !run.value; void poll() }
  function visibility() { clearTimeout(timer); if (document.visibilityState === 'visible') void poll() }
  watch(() => toValue(id), () => { run.value = undefined; restart() }, { immediate: true })
  document.addEventListener('visibilitychange', visibility)
  onBeforeUnmount(() => { disposed = true; cancel(); document.removeEventListener('visibilitychange', visibility) })
  return { run, loading, error, stopped, restart }
}
