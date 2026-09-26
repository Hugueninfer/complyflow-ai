import { chromium } from '@playwright/test'
import { mkdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const currentDirectory = dirname(fileURLToPath(import.meta.url))
const outputDirectory = resolve(currentDirectory, '../../docs/screenshots/live')
const baseURL = process.env.README_BASE_URL || 'https://complyflow-ai.onrender.com'
const executablePath = process.env.E2E_CHROMIUM_PATH || '/usr/bin/google-chrome'

await mkdir(outputDirectory, { recursive: true })

const browser = await chromium.launch({ executablePath, headless: true })

async function waitForDemoDashboard(page) {
  const created = page.waitForResponse(
    response => response.url().endsWith('/api/v1/demo-sessions')
      && response.request().method() === 'POST',
    { timeout: 60000 },
  )
  await page.getByRole('button', { name: /explorar demonstração/i }).click()
  if ((await created).status() !== 201) {
    throw new Error('The live demo session could not be created.')
  }
  await page.getByRole('heading', { name: /visão geral/i }).waitFor({ timeout: 60000 })
}

async function capture(page, filename, options = {}) {
  await page.screenshot({
    path: resolve(outputDirectory, filename),
    fullPage: options.fullPage ?? true,
  })
}

try {
  const desktop = await browser.newContext({
    baseURL,
    viewport: { width: 1440, height: 1000 },
    colorScheme: 'light',
    deviceScaleFactor: 1,
  })
  const page = await desktop.newPage()

  await page.goto('/', { waitUntil: 'networkidle', timeout: 90000 })
  await page.getByRole('form', { name: /acesso à plataforma/i }).waitFor()
  await capture(page, 'login.png')

  await waitForDemoDashboard(page)
  await page.getByText('Fornecedores analisados', { exact: true }).waitFor()
  await capture(page, 'dashboard.png')

  await page.goto('/fornecedores', { waitUntil: 'networkidle' })
  await page.getByRole('link', { name: 'NovaGuard Facilities', exact: true }).waitFor()
  await capture(page, 'suppliers.png')

  await page.getByRole('link', { name: 'NovaGuard Facilities', exact: true }).click()
  await page.getByRole('heading', { name: 'NovaGuard Facilities', exact: true }).waitFor()
  await capture(page, 'supplier-dossier.png')

  await page.getByRole('link', { name: /matriz de conformidade/i }).click()
  await page.getByRole('heading', { name: /matriz de conformidade/i }).waitFor()
  await page.getByRole('button', { name: 'Inspecionar DEMO-01', exact: true }).waitFor()
  await capture(page, 'compliance-matrix.png')

  await page.goto('/comparacoes', { waitUntil: 'networkidle' })
  await page.getByLabel('Fornecedor à esquerda').selectOption({ label: 'NovaGuard Facilities' })
  await page.getByLabel('Fornecedor à direita').selectOption({ label: 'Boreal Suprimentos Demo' })
  await page.getByLabel('Versão do checklist').selectOption({ label: 'Homologação 2026 · v1' })
  await page.getByRole('button', { name: 'Comparar fornecedores', exact: true }).click()
  await page.getByRole('region', { name: 'Resultados por requisito' }).waitFor()
  await capture(page, 'comparison.png')

  await page.goto('/auditoria', { waitUntil: 'networkidle' })
  await page.getByRole('heading', { name: /trilha de auditoria/i }).waitFor()
  await capture(page, 'audit.png')
  await desktop.close()

  const mobile = await browser.newContext({
    baseURL,
    viewport: { width: 390, height: 844 },
    colorScheme: 'light',
    deviceScaleFactor: 1,
  })
  const mobilePage = await mobile.newPage()
  await mobilePage.goto('/', { waitUntil: 'networkidle', timeout: 90000 })
  await waitForDemoDashboard(mobilePage)
  await mobilePage.getByText('Fornecedores analisados', { exact: true }).waitFor()
  if (await mobilePage.evaluate(() => document.documentElement.scrollWidth > innerWidth)) {
    throw new Error('The mobile dashboard has horizontal overflow.')
  }
  await capture(mobilePage, 'mobile-dashboard.png')
  await mobile.close()

  console.log(`Captured README screenshots from ${baseURL} in ${outputDirectory}`)
} finally {
  await browser.close()
}
