import { describe, expect, it } from 'vitest'
import { formatDate } from '@/utils/format'
import { dayMinutes, hm, repeatText } from './api'

describe('calendário da obra', () => {
  it('descreve a repetição da exceção', () => {
    const base = { repeat_every: null, repeat_unit: null, repeat_until: null }
    expect(repeatText({ ...base, kind: 'single' }, formatDate)).toBe('Única')
    expect(repeatText({ kind: 'recurring', repeat_every: 1, repeat_unit: 'week', repeat_until: null }, formatDate)).toBe('Toda semana')
    expect(repeatText({ kind: 'recurring', repeat_every: 1, repeat_unit: 'month', repeat_until: null }, formatDate)).toBe('Todo mês')
    expect(repeatText({ kind: 'recurring', repeat_every: 2, repeat_unit: 'fortnight', repeat_until: '2026-12-31' }, formatDate)).toBe('A cada 2 quinzenas até 31/12/2026')
  })

  it('soma as horas do dia (prévia; o total oficial vem da API)', () => {
    expect(dayMinutes({ start_1: '07:00', end_1: '12:00', start_2: '13:00', end_2: '16:00' })).toBe(480)
    expect(dayMinutes({ start_1: '07:00', end_1: '11:30', start_2: null, end_2: null })).toBe(270)
    expect(dayMinutes({ start_1: '12:00', end_1: '07:00', start_2: null, end_2: null })).toBe(0) // fora de ordem não conta
    expect(dayMinutes({ start_1: null, end_1: null, start_2: null, end_2: null })).toBe(0)
    expect(hm(41 * 60)).toBe('41:00')
    expect(hm(270)).toBe('4:30')
  })
})
