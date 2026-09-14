import { onScopeDispose, ref, shallowRef } from 'vue'
import { api } from '../lib/api'
export function useRemoteResource<T>() {
  const data = shallowRef<T>(), loading = ref(false), error = ref('')
  let generation = 0, controller: AbortController | undefined
  function invalidate() { generation++; controller?.abort(); data.value = undefined; loading.value = false; error.value = '' }
  async function load(path: string) {
    invalidate(); const current = generation; controller = new AbortController(); loading.value = true
    try { const response = await api.get<T>(path, { signal: controller.signal }); if (generation === current) data.value = response.data }
    catch (cause) { if (generation === current) error.value = (cause as Error).message }
    finally { if (generation === current) loading.value = false }
  }
  onScopeDispose(invalidate)
  return { data, loading, error, load, invalidate }
}
