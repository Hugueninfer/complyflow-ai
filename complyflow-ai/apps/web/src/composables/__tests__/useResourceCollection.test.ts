import { effectScope } from 'vue'
import { describe, expect, it } from 'vitest'
import { useResourceCollection } from '../useResourceCollection'

describe('Resource collection request ordering', () => {
  it('ignores a superseded request and lets a fresh read replace previously saved values', async () => {
    const responses: ((value: { id: string; name: string }[]) => void)[] = []
    const scope = effectScope()
    const collection = scope.run(() => useResourceCollection(() => new Promise<{ id: string; name: string }[]>(resolve => responses.push(resolve)), (a, b) => a.name.localeCompare(b.name)))!
    const oldRead = collection.load()
    collection.upsert({ id: 'one', name: 'Saved locally' })
    const freshRead = collection.load()
    responses[1]!([{ id: 'one', name: 'Fresh server value' }])
    await freshRead
    responses[0]!([])
    await oldRead
    expect(collection.items.value).toEqual([{ id: 'one', name: 'Fresh server value' }])
    expect(collection.loading.value).toBe(false)
    scope.stop()
  })

  it('discards late responses and errors after the view scope is disposed', async () => {
    let reject!: (reason: Error) => void
    const scope = effectScope()
    const collection = scope.run(() => useResourceCollection<{ id: string }>(() => new Promise((_resolve, failure) => { reject = failure }), () => 0))!
    const pending = collection.load()
    scope.stop()
    reject(new Error('Old screen error'))
    await pending
    collection.upsert({ id: 'late-save' })
    expect(collection.items.value).toEqual([])
    expect(collection.error.value).toBe('')
  })
})
