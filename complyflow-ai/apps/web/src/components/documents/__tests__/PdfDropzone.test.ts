import { fireEvent, screen, waitFor } from '@testing-library/vue'
import { describe, expect, it } from 'vitest'
import { json } from '../../../test/server'
import { openWorkspace, pdfDocument, supplier } from '../../../test/workspace'

async function openUpload(handler: (path: string, init: RequestInit) => Response | Promise<Response>) {
  return openWorkspace(`/fornecedores/${supplier.id}/documentos`, (path, init) => path === `/api/v1/suppliers/${supplier.id}` ? json({ data: supplier }) : handler(path, init))
}
async function choose(input: HTMLElement, files: File[]) {
  Object.defineProperty(input, 'files', { value: files, configurable: true })
  await fireEvent(input, new Event('change', { bubbles: true }))
}
describe('PDF upload', () => {
  it.each([
    ['text.txt', 'text/plain', 10, /somente pdf/i],
    ['fake.pdf', 'text/plain', 10, /somente pdf/i],
    ['hidden.exe', 'application/pdf', 10, /somente pdf/i],
    ['empty.pdf', 'application/pdf', 0, /vazio/i],
    ['large.pdf', 'application/pdf', 5242881, /5 MiB/i],
  ])('rejects %s with MIME %s and %i bytes before a request', async (name, type, size, message) => {
    let uploads = 0
    await openUpload((_path, init) => { if (init.method === 'POST') uploads++; return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) })
    const input = await screen.findByLabelText('Arquivos PDF')
    const file = new File([new Uint8Array(size)], name, { type })
    await choose(input, [file])
    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(uploads).toBe(0)
  })

  it('sends multipart only after confirmation, exposes pending state and reports dedup without duplicate list entries', async () => {
    let release!: (response: Response) => void
    let uploaded = false
    await openUpload((_path, init) => {
      if (init.method === 'POST') {
        expect(init.body).toBeInstanceOf(FormData)
        expect((init.body as FormData).get('file')).toBeInstanceOf(File)
        expect(new Headers(init.headers).has('Content-Type')).toBe(false)
        return new Promise(resolve => { release = resolve })
      }
      return json({ data: uploaded ? [pdfDocument] : [], meta: { current_page: 1, last_page: 1, total: uploaded ? 1 : 0 } })
    })
    await choose(await screen.findByLabelText('Arquivos PDF'), [new File(['%PDF'], 'valid.pdf', { type: 'application/pdf' })])
    await fireEvent.click(screen.getByRole('button', { name: 'Confirmar e enviar PDF' }))
    expect(screen.getByRole('button', { name: /enviando/i })).toBeDisabled()
    await waitFor(() => expect(release).toBeTypeOf('function'))
    uploaded = true; release(json({ data: pdfDocument }, 200))
    expect(await screen.findByRole('status')).toHaveTextContent(/já estava cadastrado/i)
    expect(await screen.findByText('generated.pdf')).toBeVisible()
    expect(screen.getAllByText('generated.pdf')).toHaveLength(1)
  })

  it.each([[413, /5 MiB/], [422, /verifique/i], [429, /limite|cota|aguarde/i]])('announces HTTP %i and allows explicit retry', async (status, message) => {
    let attempts = 0
    await openUpload((_path, init) => {
      if (init.method === 'POST') { attempts++; return attempts === 1 ? json({ errors: { file: ['internal'] } }, status) : json({ data: pdfDocument }, 201) }
      return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    })
    await choose(await screen.findByLabelText('Arquivos PDF'), [new File(['%PDF'], 'valid.pdf', { type: 'application/pdf' })])
    await fireEvent.click(screen.getByRole('button', { name: 'Confirmar e enviar PDF' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(attempts).toBe(1)
    await fireEvent.click(screen.getByRole('button', { name: 'Tentar envio novamente' }))
    expect(await screen.findByRole('status')).toHaveTextContent(/recebido/i)
  })

  it('redirects a rejected upload session to login', async () => {
    const router = await openUpload((_path, init) => init.method === 'POST' ? json({}, 401) : json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }))
    await choose(await screen.findByLabelText('Arquivos PDF'), [new File(['%PDF'], 'valid.pdf', { type: 'application/pdf' })])
    await fireEvent.click(screen.getByRole('button', { name: 'Confirmar e enviar PDF' }))
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/login'))
  })
})
