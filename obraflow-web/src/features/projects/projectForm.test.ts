import { afterEach, describe, expect, it, vi } from 'vitest'
import { geocodeAddress } from '@/utils/geocode'
import { applyMask, validateMask } from '@/utils/masks'
import { projectBody } from './form'

afterEach(() => vi.unstubAllGlobals())

describe('cadastro da obra (A-47)', () => {
  it('CNO: máscara 00.000.00000/00 e 12 dígitos', () => {
    expect(applyMask('cno', '123456789012')).toBe('12.345.67890/12')
    expect(validateMask('cno', '12.345.67890/12')).toBe(true)
    expect(validateMask('cno', '12.345.6789')).toBe('CNO deve ter 12 dígitos.')
  })

  it('campos do endereço vão para site_address; sem logradouro, o endereço não é enviado', () => {
    const body = projectBody({ name: 'DEMO', postal_code: '78068-727', street: 'Rua DEMO', number: '10', complement: null, district: 'Centro', city: 'Cuiabá', state: 'MT' })
    expect(body).toEqual({ name: 'DEMO', site_address: { postal_code: '78068-727', street: 'Rua DEMO', number: '10', complement: null, district: 'Centro', city: 'Cuiabá', state: 'MT' } })
    expect(projectBody({ name: 'DEMO', street: null, city: 'Cuiabá' })).toEqual({ name: 'DEMO' })
  })

  it('localização: endereço completo; se não achar, cai para a cidade; chamada sem cookies', async () => {
    const spy = vi.fn().mockResolvedValueOnce(Response.json([])).mockResolvedValueOnce(Response.json([{ lat: '-15.6', lon: '-56.1' }]))
    vi.stubGlobal('fetch', spy)
    expect(await geocodeAddress({ street: 'Rua DEMO', number: '10', city: 'Cuiabá', state: 'MT' })).toEqual({ point: { lat: -15.6, lng: -56.1 }, precise: false })
    expect(String(spy.mock.calls[0][0])).toContain('nominatim.openstreetmap.org/search?')
    expect(String(spy.mock.calls[0][0])).toContain('street=Rua+DEMO%2C+10')
    expect((spy.mock.calls[0][1] as RequestInit).credentials).toBe('omit')

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    expect(await geocodeAddress({ city: 'Cuiabá', state: 'MT' })).toBeNull()
  })
})
