import { afterEach, describe, expect, it, vi } from 'vitest'
import { lookupCep } from './cep'

afterEach(() => vi.unstubAllGlobals())

describe('consulta de CEP (ViaCEP)', () => {
  it('preenche o endereço e chama o ViaCEP só com os dígitos, sem cookies', async () => {
    const spy = vi.fn().mockResolvedValue(Response.json({ cep: '78068-727', logradouro: 'Rua DEMO', bairro: 'Bairro DEMO', localidade: 'Cuiabá', uf: 'mt' }))
    vi.stubGlobal('fetch', spy)

    expect(await lookupCep('78068-727')).toEqual({ street: 'Rua DEMO', district: 'Bairro DEMO', city: 'Cuiabá', state: 'MT' })
    expect(spy.mock.calls[0][0]).toBe('https://viacep.com.br/ws/78068727/json/')
    const init = spy.mock.calls[0][1] as RequestInit
    expect(init.credentials).toBe('omit')
    expect(init.headers).toBeUndefined() // nenhum cabeçalho do ObraFlow (XSRF, tenant) vai para fora
  })

  it('CEP inexistente, falha de rede ou CEP incompleto → null (preenchimento manual)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ erro: 'true' })))
    expect(await lookupCep('00000-000')).toBeNull()

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    expect(await lookupCep('78068-727')).toBeNull()

    const spy = vi.fn()
    vi.stubGlobal('fetch', spy)
    expect(await lookupCep('7806')).toBeNull()
    expect(spy).not.toHaveBeenCalled()
  })
})
