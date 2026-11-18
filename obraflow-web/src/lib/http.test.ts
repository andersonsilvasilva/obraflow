import { afterEach, describe, expect, it, vi } from 'vitest'
import { http, httpDownload, onUnauthorized } from './http'

/** Sessão Sanctum (T-02): escritas levam X-XSRF-TOKEN; GET não; 419 renova o cookie e repete; 401 avisa a aplicação. */

function headersOf(call: unknown[]): Record<string, string> {
  return (call[1] as RequestInit).headers as Record<string, string>
}

afterEach(() => vi.unstubAllGlobals())

describe('cliente HTTP', () => {
  it('escrita envia o token CSRF lido do cookie (decodificado) e não busca outro se já existe', async () => {
    vi.stubGlobal('document', { cookie: 'outro=1; XSRF-TOKEN=abc%3D%3D' })
    const spy = vi.fn().mockResolvedValue(Response.json({ ok: true }))
    vi.stubGlobal('fetch', spy)

    await http('/companies', { method: 'POST', body: { a: 1 } })

    expect(spy).toHaveBeenCalledTimes(1)
    expect(headersOf(spy.mock.calls[0])['X-XSRF-TOKEN']).toBe('abc==')
    expect((spy.mock.calls[0][1] as RequestInit).credentials).toBe('include')
  })

  it('leitura (GET) não envia token CSRF e ignora filtros vazios na query', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc' })
    const spy = vi.fn().mockResolvedValue(Response.json({ ok: true }))
    vi.stubGlobal('fetch', spy)

    await http('/companies', { query: { q: '', status: 'active', page: 2, x: undefined } })

    const url = String(spy.mock.calls[0][0])
    expect(url).toContain('status=active')
    expect(url).toContain('page=2')
    expect(url).not.toContain('q=')
    expect(headersOf(spy.mock.calls[0])['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('sem cookie, busca /sanctum/csrf-cookie antes da escrita', async () => {
    vi.stubGlobal('document', { cookie: '' })
    const spy = vi.fn().mockImplementation(async () => Response.json({ ok: true }))
    vi.stubGlobal('fetch', spy)

    await http('/units', { method: 'POST', body: {} })

    expect(String(spy.mock.calls[0][0])).toMatch(/\/sanctum\/csrf-cookie$/)
    expect(String(spy.mock.calls[1][0])).toMatch(/\/api\/v1\/units$/)
  })

  it('419 (CSRF expirado) renova o cookie e repete a escrita uma vez', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=velho' })
    const spy = vi
      .fn()
      .mockResolvedValueOnce(new Response(null, { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // csrf-cookie
      .mockResolvedValueOnce(Response.json({ data: 1 }))
    vi.stubGlobal('fetch', spy)

    await expect(http('/units', { method: 'POST', body: {} })).resolves.toEqual({ data: 1 })
    expect(spy).toHaveBeenCalledTimes(3)
    expect(String(spy.mock.calls[1][0])).toMatch(/csrf-cookie/)
  })

  it('401 avisa os ouvintes (volta ao login), exceto na própria tentativa de login', async () => {
    const listener = vi.fn()
    const off = onUnauthorized(listener)
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => Response.json({ error: { code: 'unauthenticated', message: 'x' } }, { status: 401 })))

    await expect(http('/companies')).rejects.toMatchObject({ status: 401 })
    expect(listener).toHaveBeenCalledTimes(1)
    await expect(http('/auth/login', { method: 'POST', body: {} })).rejects.toMatchObject({ code: 'unauthenticated' })
    expect(listener).toHaveBeenCalledTimes(1)
    off()
  })
})

describe('download de arquivo (relatório 4D)', () => {
  it('devolve o arquivo e o nome do Content-Disposition; escrita leva o token CSRF', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc' })
    const spy = vi.fn().mockResolvedValue(new Response('%PDF-1.7', { headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename=ORC-007-2026-v1-orcamento.pdf' } }))
    vi.stubGlobal('fetch', spy)

    const { blob, filename } = await httpDownload('/budgets/x/versions/1/report', { method: 'POST', body: { format: 'pdf' } })

    expect(filename).toBe('ORC-007-2026-v1-orcamento.pdf')
    expect(await blob.text()).toBe('%PDF-1.7')
    expect(headersOf(spy.mock.calls[0])['X-XSRF-TOKEN']).toBe('abc')
  })

  it('erro da API (JSON) vira exceção com a mensagem, sem baixar nada', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ code: 'domain_rule', message: 'PDF indisponível: o Chrome não foi encontrado.' }, { status: 422 })))

    await expect(httpDownload('/budgets/x/versions/1/report', { method: 'POST', body: { format: 'pdf' } })).rejects.toMatchObject({ status: 422 })
  })
})
