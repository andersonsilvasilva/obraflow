import { afterEach, describe, expect, it, vi } from 'vitest'
import { http } from '@/lib/http'
import { laborPreview } from './api'

afterEach(() => vi.unstubAllGlobals())

describe('catálogo de insumos', () => {
  it('prévia do balanço: salário × (1+encargos) × (1+adm) ÷ horas (o valor oficial vem da API)', () => {
    expect(laborPreview('2000', '80', '10', '220')).toBeCloseTo(18, 6)
    expect(laborPreview('2537,40', '79,65', '15', '220')).toBeCloseTo(23.83, 2)
    expect(laborPreview('2000', '', '', '220')).toBeCloseTo(9.0909, 3)
    expect(laborPreview('', '80', '10', '220')).toBeNull()
    expect(laborPreview('2000', '80', '10', '0')).toBeNull()
  })

  it('envio de planilha usa multipart: sem Content-Type JSON (o navegador define o boundary)', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc' })
    const spy = vi.fn().mockResolvedValue(Response.json({ data: {} }))
    vi.stubGlobal('fetch', spy)
    const form = new FormData()
    form.append('kind', 'material')

    await http('/resources/import/preview', { method: 'POST', body: form })

    const init = spy.mock.calls[0][1] as RequestInit
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect(init.body).toBe(form)
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc')
  })
})
