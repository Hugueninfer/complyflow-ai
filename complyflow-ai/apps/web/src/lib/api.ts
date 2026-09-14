export class ApiError extends Error {
  status: number
  constructor(status: number) {
    const messages: Record<number, string> = {
      0: 'Não foi possível conectar. Verifique sua conexão e tente novamente.',
      401: 'Sua sessão expirou. Entre novamente para continuar.',
      403: 'Sua conta não tem permissão para esta ação.',
      419: 'A sessão de segurança expirou. Tente novamente.',
      422: 'Verifique os dados informados e tente novamente.',
      429: 'Muitas tentativas. Aguarde um momento e tente novamente.',
    }
    super(messages[status] ?? 'Não foi possível concluir a solicitação. Tente novamente.')
    this.name = 'ApiError'
    this.status = status
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

async function request<T>(method: string, path: string, body?: unknown): Promise<{ data: T }> {
  const mutation = method !== 'GET'
  if (mutation && !csrfToken()) await csrf()
  for (let attempt = 0; attempt < 2; attempt++) {
    const headers = new Headers({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
    const token = csrfToken()
    if (mutation && token) headers.set('X-XSRF-TOKEN', token)
    if (body !== undefined) headers.set('Content-Type', 'application/json')
    const response = await send(`/api/v1${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
    if (response.status === 419 && mutation && attempt === 0) { await csrf(); continue }
    if (response.status === 401) unauthorized?.()
    if (!response.ok) throw new ApiError(response.status)
    if (response.status === 204) return { data: undefined as T }
    return { data: await response.json() as T }
  }
  throw new ApiError(419)
}

// The transport wrapper mirrors axios: response.data is the complete server JSON.
export const api = {
  get: <T = unknown>(path: string) => request<T>('GET', path),
  post: <T = unknown>(path: string, body?: unknown) => request<T>('POST', path, body),
}
