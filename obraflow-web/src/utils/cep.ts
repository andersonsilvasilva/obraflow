/**
 * Consulta de CEP no ViaCEP (serviço público e gratuito). Chamada DIRETA do navegador com `fetch` simples:
 * sem cookies, sem o cabeçalho XSRF e sem passar pelo cliente da API do ObraFlow — só os 8 dígitos do CEP saem.
 * Qualquer falha (CEP inexistente, rede, tempo esgotado) devolve null e o usuário preenche à mão.
 */
export interface CepAddress {
  street: string
  district: string
  city: string
  state: string
}

const TIMEOUT_MS = 5000

export async function lookupCep(cep: string): Promise<CepAddress | null> {
  const digits = cep.replace(/\D/g, '')
  if (digits.length !== 8) return null

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS)
  try {
    const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`, { signal: controller.signal, credentials: 'omit' })
    if (!res.ok) return null
    const data = (await res.json()) as { erro?: boolean | string; logradouro?: string; bairro?: string; localidade?: string; uf?: string }
    if (data.erro) return null
    return { street: data.logradouro ?? '', district: data.bairro ?? '', city: data.localidade ?? '', state: (data.uf ?? '').toUpperCase() }
  } catch {
    return null
  } finally {
    clearTimeout(timer)
  }
}
