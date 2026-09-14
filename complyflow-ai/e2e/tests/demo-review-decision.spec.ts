import { expect, test, type Page } from '@playwright/test'

async function enterDemo(page: Page) {
  await page.goto('/')
  const [created] = await Promise.all([
    // The 512 MiB runtime has one FPM child: concurrent visitors queue their
    // CSRF + demo creation before navigation. Keep the overall test at 45 s.
    page.waitForResponse((response) => response.url().endsWith('/api/v1/demo-sessions')
      && response.request().method() === 'POST', { timeout: 30000 }),
    page.getByRole('button', { name: /explorar demonstração/i }).click(),
  ])
  expect(created.status()).toBe(201)
  await expect(page.getByRole('heading', { name: /visão geral/i })).toBeVisible()
  await page.goto('/fornecedores')
  await page.getByRole('link', { name: 'NovaGuard Facilities', exact: true }).click()
  await page.getByRole('link', { name: /matriz de conformidade/i }).click()
  await expect(page.getByRole('heading', { name: /matriz de conformidade/i })).toBeVisible()
}

test('visitor reviews evidence and records a human decision', async ({ page }, testInfo) => {
  await enterDemo(page)
  await expect(page.getByRole('button', { name: 'Inspecionar DEMO-01', exact: true })).toBeVisible()
  await page.screenshot({ path: testInfo.outputPath('demo-matrix.png'), fullPage: true })
  for (const code of ['DEMO-01', 'DEMO-02', 'DEMO-03', 'DEMO-04']) {
    await page.getByRole('button', { name: `Inspecionar ${code}`, exact: true }).click()
    const dialog = page.getByRole('dialog')
    if (code === 'DEMO-02') {
      await expect(dialog.getByText(/Página 1/)).toBeVisible()
      await expect(dialog.locator('blockquote')).toContainText('dados pessoais')
      await dialog.getByLabel('Status revisado').selectOption('met')
    }
    await dialog.getByLabel('Justificativa humana', { exact: true }).fill('Inspeção manual do material fictício e das limitações registradas.')
    await dialog.getByLabel(/observação opcional/i).fill('Evidência verificada manualmente.')
    await dialog.getByRole('checkbox').check()
    await dialog.getByRole('button', { name: 'Salvar revisão' }).click()
    await expect(dialog.getByRole('status')).toContainText(/revisão registrada/i)
    if (code === 'DEMO-02') {
      await expect(dialog.locator('.finding-ai-suggestion')).toContainText('Parcial')
      await expect(dialog.locator('.human-record')).toContainText('Conforme')
    }
    await dialog.getByRole('button', { name: 'Fechar evidência' }).click()
  }
  await page.getByRole('combobox', { name: 'Decisão final humana', exact: true }).selectOption('conditional')
  await page.getByLabel('Justificativa da decisão').fill('Decisão humana fictícia: condicionado à entrega do plano de continuidade.')
  await page.getByRole('checkbox', { name: /assumo a responsabilidade/i }).check()
  const decisionSaved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/decisions'))
  await page.getByRole('button', { name: 'Registrar decisão humana', exact: true }).click()
  const decisionResponse = await decisionSaved
  expect(decisionResponse.status()).toBe(201)
  const decisionId = (await decisionResponse.json()).data.id
  await expect(page.getByRole('heading', { name: /decisão humana registrada/i })).toBeVisible()
  await page.reload()
  await expect(page.getByText('Condicionado por decisão humana', { exact: true })).toBeVisible()
  await page.screenshot({ path: testInfo.outputPath('demo-decision.png'), fullPage: true })
  await page.goto('/auditoria')
  await expect(page.getByText(/decisão.*registrada/i).first()).toBeVisible()
  await expect(page.getByText(decisionId, { exact: true })).toBeVisible()
  await expect(page.getByText(/verificad/i).first()).toBeVisible()
  await page.screenshot({ path: testInfo.outputPath('demo-audit.png'), fullPage: true })
})

test('simultaneous visitors have isolated graphs, persisted deep links and mobile evidence', async ({ browser, baseURL }, testInfo) => {
  const first = await browser.newContext({ baseURL })
  const second = await browser.newContext({ baseURL, viewport: { width: 390, height: 844 } })
  const a = await first.newPage(), b = await second.newPage()
  await Promise.all([enterDemo(a), enterDemo(b)])
  expect(a.url()).not.toBe(b.url())
  const foreign = new URL(a.url()).pathname
  const own = b.url()
  const response = await b.request.get('/api/v1/analyses/' + foreign.split('/')[2] + '/findings')
  expect(response.status()).toBe(404)
  const findings = (await (await a.request.get('/api/v1/analyses/' + foreign.split('/')[2] + '/findings')).json()).data
  const foreignFinding = findings.find((finding: { requirement: { code: string } }) => finding.requirement.code === 'DEMO-02')
  expect((await b.request.get('/api/v1/documents/' + foreignFinding.citations[0].document_id)).status()).toBe(404)
  const csrf = (await second.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')!
  const forbidden = await b.request.post('/api/v1/findings/' + foreignFinding.id + '/reviews', {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value), 'Idempotency-Key': 'cross-demo-attempt', Accept: 'application/json' },
    data: { status: 'met', justification: 'Tentativa de acesso cruzado fictícia.', expected_review_id: null },
  })
  expect(forbidden.status()).toBe(404)
  await a.getByRole('button', { name: 'Inspecionar DEMO-02', exact: true }).click()
  await a.getByRole('dialog').getByLabel('Justificativa humana', { exact: true }).fill('Parecer exclusivo da primeira sessão fictícia.')
  await a.getByRole('dialog').getByRole('checkbox').check()
  await a.getByRole('dialog').getByRole('button', { name: 'Salvar revisão' }).click()
  await expect(a.getByRole('dialog').getByRole('status')).toContainText(/revisão registrada/i)
  await b.reload()
  await expect(b.getByRole('heading', { name: /matriz de conformidade/i })).toBeVisible()
  await b.goto(own)
  await b.getByRole('button', { name: 'Inspecionar DEMO-02', exact: true }).click()
  await expect(b.getByRole('dialog').locator('blockquote')).toContainText('dados pessoais')
  await expect(b.getByRole('dialog').getByText('Ainda não há revisão registrada.', { exact: true })).toBeVisible()
  await expect(b.getByText('Parecer exclusivo da primeira sessão fictícia.')).toHaveCount(0)
  expect(await b.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await b.screenshot({ path: testInfo.outputPath('demo-evidence-mobile.png') })
  await b.keyboard.press('Escape')
  await expect(b.getByRole('dialog')).toHaveCount(0)
  await first.close(); await second.close()
})
