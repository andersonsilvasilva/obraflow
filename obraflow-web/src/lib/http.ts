import { parseApiError } from './api-error'
import { env } from './env'
import { getActiveTenant, notifyTenantMismatch, TENANT_HINT_HEADER } from './tenant-context'

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export type Query = Record<string, string | number | boolean | null | undefined>

interface RequestOptions {
  method?: Method
  body?: unknown
  query?: Query
  signal?: AbortSignal
}

const XSRF_COOKIE = 'XSRF-TOKEN'

/** Origem da API (sem /api/v1): onde fica /sanctum/csrf-cookie. */
const apiOrigin = () => new URL(env.apiUrl).origin

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfPromise: Promise<void> | null = null

/** Garante o cookie XSRF-TOKEN do Sanctum (uma busca por vez). */
export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie(XSRF_COOKIE)) return
  csrfPromise ??= fetch(`${apiOrigin()}/sanctum/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } })
    .then(() => undefined)
    .finally(() => {
      csrfPromise = null
    })
  await csrfPromise
}

/** Ouvintes de 401 (sessão expirada): a aplicação volta ao login. */
const unauthorizedListeners = new Set<() => void>()
export function onUnauthorized(listener: () => void): () => void {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

/**
 * Cliente HTTP único. O tenant NUNCA é enviado como fonte de verdade: o backend o identifica pela sessão.
 * O cabeçalho X-Tenant-Hint (tenant exibido nesta aba) é só uma dica de consistência: divergência => 409
 * `tenant_mismatch` sem efeito colateral. Escritas levam o X-XSRF-TOKEN (sessão Sanctum, T-02).
 */
export async function http<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const url = new URL(`${env.apiUrl}${path}`)
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value))
  }

  const send = async () => {
    const mutating = method !== 'GET'
    if (mutating) await ensureCsrfCookie()
    const hint = getActiveTenant()
    const xsrf = readCookie(XSRF_COOKIE)

    return fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(hint ? { [TENANT_HINT_HEADER]: hint } : {}),
        ...(mutating && xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      credentials: 'include',
      signal: options.signal,
    })
  }

  let response = await send()
  if (response.status === 419) {
    // token CSRF expirado: renova uma vez e repete
    await ensureCsrfCookie(true)
    response = await send()
  }

  if (!response.ok) {
    const error = await parseApiError(response)
    if (error.status === 409 && error.code === 'tenant_mismatch') notifyTenantMismatch()
    if (error.status === 401 && !path.startsWith('/auth/login')) unauthorizedListeners.forEach((l) => l())
    throw error
  }
  if (response.status === 204) return undefined as T
  return (await response.json()) as T
}
