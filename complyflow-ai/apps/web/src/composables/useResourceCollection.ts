import { onScopeDispose, ref, shallowRef } from 'vue'

// Keep successful writes newer than a GET's snapshot, without pinning stale
// local records over subsequent authoritative reads.
export function useResourceCollection<T extends { id: string }>(read: () => Promise<T[]>, compare: (a: T, b: T) => number) {
  const items = shallowRef<T[]>([])
  const loading = ref(true), error = ref('')
  let generation = 0, revision = 0, disposed = false
  const writes = new Map<string, { revision: number; value: T }>()

  async function load() {
    if (disposed) return
    const request = ++generation, readRevision = revision
    loading.value = true; error.value = ''
    try {
      const result = await read()
      if (disposed || request !== generation) return
      const reconciled = new Map(result.map(item => [item.id, item]))
      for (const [id, write] of writes) {
        if (write.revision > readRevision) reconciled.set(id, write.value)
      }
      items.value = [...reconciled.values()].sort(compare)
      writes.clear()
    } catch (cause) {
      if (!disposed && request === generation) error.value = (cause as Error).message
    } finally {
      if (!disposed && request === generation) loading.value = false
    }
  }

  function upsert(value: T) {
    if (disposed) return
    writes.set(value.id, { revision: ++revision, value })
    items.value = [...items.value.filter(item => item.id !== value.id), value].sort(compare)
  }

  onScopeDispose(() => { disposed = true; generation++; writes.clear() })
  return { items, loading, error, load, upsert }
}
