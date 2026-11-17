/**
 * Coordenadas a partir do endereço da obra (A-47) pelo Nominatim (OpenStreetMap, serviço público e gratuito).
 * Só roda quando o usuário pede ("Localizar pelo endereço") — política de uso do Nominatim: uma consulta por vez,
 * sem automação em massa. Chamada direta do navegador, sem cookies nem cabeçalhos do ObraFlow. Falha → null.
 */
export interface GeoPoint {
  lat: number
  lng: number
}

export interface GeoAddress {
  street?: string | null
  number?: string | null
  district?: string | null
  city?: string | null
  state?: string | null
  postal_code?: string | null
}

const TIMEOUT_MS = 8000

async function search(params: Record<string, string>): Promise<GeoPoint | null> {
  const query = new URLSearchParams({ format: 'jsonv2', limit: '1', countrycodes: 'br', ...params })
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS)
  try {
    const res = await fetch(`https://nominatim.openstreetmap.org/search?${query}`, { signal: controller.signal, credentials: 'omit' })
    if (!res.ok) return null
    const rows = (await res.json()) as { lat: string; lon: string }[]
    const first = rows[0]
    return first ? { lat: Number(first.lat), lng: Number(first.lon) } : null
  } catch {
    return null
  } finally {
    clearTimeout(timer)
  }
}

/** Tenta o endereço completo; se não achar, cai para cidade/UF (o pino pode ser ajustado à mão). */
export async function geocodeAddress(a: GeoAddress): Promise<{ point: GeoPoint; precise: boolean } | null> {
  const street = [a.street, a.number].filter(Boolean).join(', ')
  if (street && a.city) {
    const p = await search({ street, city: a.city, state: a.state ?? '', ...(a.postal_code ? { postalcode: a.postal_code } : {}) })
    if (p) return { point: p, precise: true }
  }
  if (a.city) {
    const p = await search({ city: a.city, state: a.state ?? '' })
    if (p) return { point: p, precise: false }
  }
  return null
}
