import { render } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import StatusBadge from '../StatusBadge.vue'

describe('StatusBadge', () => {
  it.each([
    ['met', 'Conforme', 'Status conforme'],
    ['partial', 'Parcial', 'Status parcial'],
    ['missing', 'Não conforme', 'Status não conforme'],
    ['inconclusive', 'Inconclusivo', 'Status inconclusivo'],
  ] as const)('identifies %s with visible text and an accessible icon', (status, label, icon) => {
    const view = render(StatusBadge, { props: { status } })
    expect(view.getByText(label)).toBeVisible()
    expect(view.getByRole('img', { name: icon })).toBeVisible()
  })
})
