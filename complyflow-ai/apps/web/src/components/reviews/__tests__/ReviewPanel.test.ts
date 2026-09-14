import { fireEvent, render, screen } from '@testing-library/vue'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ReviewPanel from '../ReviewPanel.vue'
import { fakeServer, json, session } from '../../../test/server'
import { finding, humanReview } from '../../../test/analysis'
import { useAuthStore } from '../../../stores/auth'
import type { Session } from '../../../types/domain'
function open() { const pinia = createPinia(); setActivePinia(pinia); useAuthStore().session = session as Session; return render(ReviewPanel, { props: { finding }, global: { plugins: [pinia] } }) }
describe('Human review', () => {
  it('blocks conflicting drafts, preserves human input and requires reopening after refresh', async () => {
    let calls = 0
    fakeServer(() => { calls++; return json({}, 409) }); const view = open()
    await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Inspeção local.'); await fireEvent.click(screen.getByLabelText(/confirmo que inspecionei/i)); await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    expect(screen.getByRole('alert')).toHaveTextContent(/revisão mais recente/i)
    expect(screen.getByLabelText('Justificativa humana')).toHaveValue('Inspeção local.')
    expect(screen.getByRole('button', { name: 'Salvar revisão' })).toBeDisabled()
    expect(view.emitted().conflict).toHaveLength(1); expect(calls).toBe(1)
  })
  it('requires justification and explicit human confirmation before sending', async () => {
    let calls = 0; fakeServer(() => { calls++; return json({ data: humanReview }, 201) }); open()
    await fireEvent.update(screen.getByLabelText('Status revisado'), 'partial')
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' }))
    expect(screen.getByRole('alert')).toHaveTextContent(/justificativa/i); expect(calls).toBe(0)
    await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Validade limitada.')
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' }))
    expect(screen.getByRole('alert')).toHaveTextContent(/confirme/i); expect(calls).toBe(0)
  })
  it('retains the key for uncertain retries, reports conflict and emits a separate review', async () => {
    const keys: string[] = []; let fail = true
    fakeServer((_path, init) => { keys.push(new Headers(init.headers).get('Idempotency-Key')!); expect(JSON.parse(String(init.body))).toMatchObject({ status: 'partial', expected_review_id: null }); return fail ? json({}, 500) : json({ data: humanReview }, 201) })
    const view = open()
    await fireEvent.update(screen.getByLabelText('Status revisado'), 'partial'); await fireEvent.update(screen.getByLabelText('Justificativa humana'), 'Validade limitada.'); await fireEvent.click(screen.getByLabelText(/confirmo que inspecionei/i))
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    expect(screen.getByRole('alert')).toBeVisible(); fail = false
    await fireEvent.click(screen.getByRole('button', { name: 'Salvar revisão' })); await flushPromises()
    expect(keys[0]).toBeTruthy(); expect(keys[1]).toBe(keys[0]); expect(view.emitted().saved?.[0]).toEqual([humanReview]); expect(finding.status).toBe('met')
  })
})
