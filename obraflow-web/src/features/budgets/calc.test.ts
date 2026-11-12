import { describe, expect, it } from 'vitest'
import { compositions, initialBudgetGroups } from '@/mocks/data'
import { abcCurve, budgetTotals, compositionUnitCost, costByType, itemTotal } from './calc'

describe('cálculos demonstrativos de orçamento', () => {
  it('soma insumos × coeficiente no custo da composição', () => {
    // 25*1.2 + 0.015*420 + 0.9*24 + 0.9*17 = 30 + 6.3 + 21.6 + 15.3
    expect(compositionUnitCost(compositions[0])).toBeCloseTo(73.2, 6)
  })

  it('usa o preço da composição vinculada no total do item', () => {
    const item = initialBudgetGroups[1].items[0] // 940 m² × 73,20
    expect(itemTotal(item, compositions)).toBeCloseTo(940 * 73.2, 4)
  })

  it('aplica BDI percentual sobre o custo direto', () => {
    const t = budgetTotals(initialBudgetGroups, compositions, 25)
    expect(t.bdiValue).toBeCloseTo(t.direct * 0.25, 6)
    expect(t.total).toBeCloseTo(t.direct * 1.25, 6)
  })

  it('desconto incide sobre custo direto + BDI e o total nunca fica negativo', () => {
    const t = budgetTotals(initialBudgetGroups, compositions, 25, 10)
    expect(t.discountValue).toBeCloseTo((t.direct + t.bdiValue) * 0.1, 6)
    expect(t.total).toBeCloseTo(t.direct * 1.25 * 0.9, 6)
    expect(budgetTotals(initialBudgetGroups, compositions, 25, 100).total).toBeCloseTo(0, 6)
  })

  it('curva ABC é decrescente, acumula 100% e inicia na classe A', () => {
    const rows = abcCurve(initialBudgetGroups, compositions)
    expect(rows[0].klass).toBe('A')
    expect(rows.at(-1)!.cumulative).toBeCloseTo(1, 6)
    for (let i = 1; i < rows.length; i++) expect(rows[i - 1].total).toBeGreaterThanOrEqual(rows[i].total)
  })

  it('distribuição por tipo preserva o custo direto', () => {
    const byType = costByType(initialBudgetGroups, compositions)
    const sum = Object.values(byType).reduce((a, b) => a + b, 0)
    expect(sum).toBeCloseTo(budgetTotals(initialBudgetGroups, compositions, 0).direct, 4)
  })

  it('orçamento vazio não gera divisão por zero', () => {
    expect(abcCurve([], compositions)).toEqual([])
    expect(budgetTotals([], compositions, 25).total).toBe(0)
  })
})
