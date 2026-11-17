import { describe, expect, it } from 'vitest'
import { nextStep, sheetDone, stepState, steps } from './steps'

describe('Primeiros passos', () => {
  it('ordem do percurso: empresa → cliente → (funcionário) → obra → orçamento → planilha → aprovação', () => {
    expect(steps.map((s) => s.id)).toEqual(['company', 'customer', 'employee', 'project', 'budget', 'sheet', 'approval'])
    expect(steps.find((s) => s.id === 'employee')?.optional).toBe(true)
  })

  it('estado do passo: sem permissão, carregando, pendente, feito', () => {
    expect(stepState(false, 5)).toBe('no-access')
    expect(stepState(true, undefined)).toBe('loading')
    expect(stepState(true, 0)).toBe('pending')
    expect(stepState(true, 2)).toBe('done')
  })

  it('planilha conta como feita com orçamento enviado ou aprovado', () => {
    expect(sheetDone(0, 0)).toBe(0)
    expect(sheetDone(1, 0)).toBe(1)
    expect(sheetDone(0, 3)).toBe(3)
    expect(sheetDone(undefined, 1)).toBeUndefined()
  })

  it('"você está aqui" é o primeiro passo obrigatório pendente (opcionais e sem acesso não contam)', () => {
    const base = { company: 'done', customer: 'done', employee: 'pending', project: 'pending', budget: 'pending', sheet: 'pending', approval: 'pending' } as const
    expect(nextStep({ ...base })).toBe('project')
    expect(nextStep({ ...base, project: 'no-access' })).toBe('budget')
    expect(nextStep({ company: 'done', customer: 'done', employee: 'done', project: 'done', budget: 'done', sheet: 'done', approval: 'done' })).toBeNull()
  })
})
