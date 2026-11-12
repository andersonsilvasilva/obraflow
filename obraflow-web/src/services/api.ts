import { env } from '@/lib/env'
import { http } from '@/lib/http'

const delay = (ms = 250) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Ponto único de troca entre dados demonstrativos e API real.
 * Com VITE_USE_MOCKS=true usa `mock()`; caso contrário chama `path` via HTTP.
 * Nesta etapa não há integração com o backend (ver PROMPT 003).
 */
export async function fetchResource<T>(path: string, mock: () => T): Promise<T> {
  if (env.useMocks) {
    await delay()
    return structuredClone(mock())
  }
  return http<T>(path)
}
