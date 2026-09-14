import { cleanup, fireEvent, screen } from '@testing-library/vue'
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { openWorkspace } from '../../test/workspace'
import { json } from '../../test/server'
import { run } from '../../test/analysis'

afterEach(() => { cleanup(); vi.useRealTimers() })
describe('Analysis progress', () => {
  it.each(['/ajuda', '/analises/run-other'])('does not navigate when an old retry finishes after leaving for %s', async destination => {
    let release!: (response: Response) => void
    const router = await openWorkspace('/analises/run-1', (path, init) => {
      if (init.method === 'POST') return new Promise(resolve => { release = resolve })
      return json({ data: { ...run, id: path.split('/').at(-1), status: 'failed' } })
    }, ['analysis.view', 'analysis.run'])
    await flushPromises(); await fireEvent.click(screen.getByRole('button', { name: /reprocessar/i })); await flushPromises()
    await router.push(destination); await flushPromises()
    release(json({ data: { ...run, id: 'run-retried' } }, 202)); await flushPromises()
    expect(router.currentRoute.value.path).toBe(destination)
    if (destination.includes('run-other')) expect(screen.getByRole('button', { name: /reprocessar/i })).toBeEnabled()
  })
  it('loads immediately, polls once at a time and stops after completion', async () => {
    vi.useFakeTimers(); let calls = 0; let release!: (r: Response) => void
    await openWorkspace('/analises/run-1', () => { calls++; return calls === 1 ? new Promise(resolve => { release = resolve }) : json({ data: { ...run, status: 'completed', progress: 100 } }) }, ['analysis.view'])
    expect(calls).toBe(1)
    await vi.advanceTimersByTimeAsync(10000); expect(calls).toBe(1)
    release(json({ data: run })); await flushPromises()
    await vi.advanceTimersByTimeAsync(2500); await flushPromises()
    expect(screen.getByRole('link', { name: /matriz de conformidade/i })).toHaveAttribute('href', '/analises/run-1/matriz')
    await vi.advanceTimersByTimeAsync(20000); expect(calls).toBe(2)
  })
  it.each([401, 404])('stops polling on permanent HTTP %s', async status => {
    vi.useFakeTimers(); let calls = 0
    await openWorkspace('/analises/run-1', () => { calls++; return json({}, status) }, ['analysis.view'])
    await flushPromises(); await vi.advanceTimersByTimeAsync(30000)
    expect(calls).toBe(1)
    if (status === 404) expect(screen.getByRole('alert')).toHaveTextContent(/não encontrado/i)
  })
  it('backs off 429 and transient failures then recovers', async () => {
    vi.useFakeTimers(); let calls = 0
    await openWorkspace('/analises/run-1', () => { calls++; return calls < 3 ? json({}, calls === 1 ? 429 : 500) : json({ data: { ...run, status: 'failed' } }) }, ['analysis.view'])
    await flushPromises(); await vi.advanceTimersByTimeAsync(2500); expect(calls).toBe(1)
    await vi.advanceTimersByTimeAsync(2500); expect(calls).toBe(2)
    await vi.advanceTimersByTimeAsync(10000); expect(calls).toBe(3)
    expect(screen.getByRole('heading', { name: /análise interrompida/i, level: 2 })).toBeVisible()
    expect(screen.queryByRole('button', { name: /reprocessar/i })).toBeNull()
  })
  it('aborts requests on navigation and ignores stale completion', async () => {
    let signal: AbortSignal | undefined
    const router = await openWorkspace('/analises/run-1', (_path, init) => { signal = init.signal!; return new Promise(() => {}) }, ['analysis.view'])
    await router.push('/ajuda'); await flushPromises()
    expect(signal?.aborted).toBe(true)
  })
  it('reprocesses failed analyses with an idempotency key and navigates to the new run', async () => {
    let key = ''
    const router = await openWorkspace('/analises/run-1', (path, init) => {
      if (init.method === 'POST') { key = new Headers(init.headers).get('Idempotency-Key')!; expect(path).toBe('/api/v1/analyses/run-1/retry'); return json({ data: { ...run, id: 'run-2' } }, 202) }
      return json({ data: { ...run, status: 'failed' } })
    }, ['analysis.view', 'analysis.run'])
    await flushPromises(); await fireEvent.click(screen.getByRole('button', { name: /reprocessar/i })); await flushPromises()
    expect(key).toMatch(/^[\w-]+$/); expect(router.currentRoute.value.path).toBe('/analises/run-2')
  })
})
