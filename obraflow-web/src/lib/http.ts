import { parseApiError } from './api-error'
import { env } from './env'
import { getActiveTenant, notifyTenantMismatch, TENANT_HINT_HEADER } from './tenant-context'

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: Method
  body?: unknown
  query?: Record<string, string | number | boolean | undefined>
  signal?: AbortSignal
}

/**
 * Cliente HTTP único. O tenant NUNCA é enviado como fonte de verdade: o backend o identifica pela sessão/token.
 * O cabeçalho X-Tenant-Hint (tenant exibido nesta aba) é só uma dica de consistência: divergência => 409
 * `tenant_mismatch` sem efeito colateral.
 */
export async function http<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const url = new URL(`${env.apiUrl}${path}`)
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined) url.searchParams.set(key, String(value))
  }

  const hint = getActiveTenant()

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(hint ? { [TENANT_HINT_HEADER]: hint } : {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    credentials: 'include',
    signal: options.signal,
  })

  if (!response.ok) {
    const error = await parseApiError(response)
    if (error.status === 409 && error.code === 'tenant_mismatch') notifyTenantMismatch()
    throw error
  }
  if (response.status === 204) return undefined as T
  return (await response.json()) as T
}
