export class ApiError extends Error {
  status: number
  fields: Record<string, string>
  code?: string
  constructor(status: number, fields: Record<string, string> = {}, code?: string) {
    const messages: Record<number, string> = {
      0: 'Não foi possível conectar. Verifique sua conexão e tente novamente.',
      401: 'Sua sessão expirou. Entre novamente para continuar.',
      403: 'Sua conta não tem permissão para esta ação.',
      404: 'Registro não encontrado ou indisponível para sua organização.',
      409: 'Este registro foi alterado ou esta versão já existe. Atualize a página e tente novamente.',
      413: 'O PDF excede o limite de 5 MiB por arquivo.',
      419: 'A sessão de segurança expirou. Tente novamente.',
      422: 'Verifique os dados informados e tente novamente.',
      429: 'Muitas tentativas. Aguarde um momento e tente novamente.',
    }
    const safeCodeMessages: Record<string, string> = {
      demo_storage_quota_exceeded: 'A cota de armazenamento desta demonstração foi esgotada. Inicie uma nova demonstração para enviar mais documentos.',
      ai_daily_quota_exceeded: 'A cota diária de análises por IA foi atingida. Tente novamente após o próximo dia UTC.',
    }
    const safeCode = code && safeCodeMessages[code] ? code : undefined
    super(safeCode ? safeCodeMessages[safeCode] : messages[status] ?? 'Não foi possível concluir a solicitação. Tente novamente.')
    this.name = 'ApiError'
    this.status = status
    this.fields = fields
    this.code = safeCode
  }
}

let unauthorized: (() => void) | undefined
export function onUnauthorized(handler: () => void) { unauthorized = handler }

function csrfToken() {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : undefined
}

async function send(path: string, init: RequestInit) {
  try {
    return await fetch(path, { ...init, credentials: 'include' })
  } catch {
    throw new ApiError(0)
  }
}

async function csrf() {
  const response = await send('/sanctum/csrf-cookie', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
  if (!response.ok) throw new ApiError(response.status)
}

export interface RequestOptions { signal?: AbortSignal; idempotencyKey?: string }
async function request<T>(method: string, path: string, body?: unknown, options: RequestOptions = {}): Promise<{ data: T; status: number }> {
  const mutation = method !== 'GET'
  if (mutation && !csrfToken()) await csrf()
  for (let attempt = 0; attempt < 2; attempt++) {
    const headers = new Headers({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
    if (options.idempotencyKey) headers.set('Idempotency-Key', options.idempotencyKey)
    const token = csrfToken()
    if (mutation && token) headers.set('X-XSRF-TOKEN', token)
    const multipart = body instanceof FormData
    if (body !== undefined && !multipart) headers.set('Content-Type', 'application/json')
    const response = await send(`/api/v1${path}`, { method, headers, signal: options.signal, body: body === undefined ? undefined : multipart ? body : JSON.stringify(body) })
    if (response.status === 419 && mutation && attempt === 0) { await csrf(); continue }
    if (response.status === 401) unauthorized?.()
    if (!response.ok) {
      const fields: Record<string, string> = {}
      const payload = await response.json().catch(() => null) as { errors?: unknown; code?: unknown } | null
      if (response.status === 422) {
        if (payload?.errors && typeof payload.errors === 'object') {
          for (const key of Object.keys(payload.errors)) fields[key] = 'Verifique este campo.'
        }
      }
      throw new ApiError(response.status, fields, typeof payload?.code === 'string' ? payload.code : undefined)
    }
    if (response.status === 204) return { data: undefined as T, status: response.status }
    return { data: await response.json() as T, status: response.status }
  }
  throw new ApiError(419)
}

// The transport wrapper mirrors axios: response.data is the complete server JSON.
export const api = {
  get: <T = unknown>(path: string, options?: RequestOptions) => request<T>('GET', path, undefined, options),
  post: <T = unknown>(path: string, body?: unknown, options?: RequestOptions) => request<T>('POST', path, body, options),
  put: <T = unknown>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T = unknown>(path: string) => request<T>('DELETE', path),
}
