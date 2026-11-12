import type { BudgetGroup, BudgetItem, Composition, ResourceType } from '@/types'

/**
 * Cálculos DEMONSTRATIVOS de orçamento. Não representam metodologia oficial
 * (SINAPI, SUDECAP etc.) — a regra definitiva depende de decisão de negócio.
 */

export const compositionUnitCost = (c: Composition) => c.inputs.reduce((sum, i) => sum + i.coefficient * i.unitPrice, 0)

/** Preço unitário efetivo: o da composição vinculada, quando houver. */
export function effectiveUnitPrice(item: BudgetItem, compositions: Composition[]): number {
  if (item.compositionId) {
    const c = compositions.find((x) => x.id === item.compositionId)
    if (c) return compositionUnitCost(c)
  }
  return item.unitPrice
}

export const itemTotal = (item: BudgetItem, compositions: Composition[]) =>
  item.quantity * effectiveUnitPrice(item, compositions)

export const groupTotal = (group: BudgetGroup, compositions: Composition[]) =>
  group.items.reduce((sum, item) => sum + itemTotal(item, compositions), 0)

export const directCost = (groups: BudgetGroup[], compositions: Composition[]) =>
  groups.reduce((sum, g) => sum + groupTotal(g, compositions), 0)

export interface BudgetTotals {
  direct: number
  bdiValue: number
  discountValue: number
  total: number
}

/** BDI recebido como percentual (ex.: 25 = 25%). */
export function budgetTotals(groups: BudgetGroup[], compositions: Composition[], bdiPercent: number, discountPercent = 0): BudgetTotals {
  const direct = directCost(groups, compositions)
  const bdiValue = direct * (bdiPercent / 100)
  const discountValue = (direct + bdiValue) * (discountPercent / 100)
  return { direct, bdiValue, discountValue, total: direct + bdiValue - discountValue }
}

export interface AbcRow {
  item: BudgetItem
  total: number
  share: number
  cumulative: number
  klass: 'A' | 'B' | 'C'
}

/** Curva ABC: A até 80% acumulado, B até 95%, C o restante. */
export function abcCurve(groups: BudgetGroup[], compositions: Composition[]): AbcRow[] {
  const rows = groups
    .flatMap((g) => g.items)
    .map((item) => ({ item, total: itemTotal(item, compositions) }))
    .sort((a, b) => b.total - a.total)
  const sum = rows.reduce((s, r) => s + r.total, 0)
  let acc = 0
  return rows.map((r) => {
    const share = sum === 0 ? 0 : r.total / sum
    const before = acc
    acc += share
    // a classe depende do acumulado ANTES do item: o item que cruza o limite pertence à faixa anterior
    const klass = before < 0.8 ? 'A' : before < 0.95 ? 'B' : 'C'
    return { ...r, share, cumulative: acc, klass }
  })
}

export function costByType(groups: BudgetGroup[], compositions: Composition[]): Record<ResourceType, number> {
  const acc: Record<ResourceType, number> = { service: 0, material: 0, labor: 0, equipment: 0 }
  for (const item of groups.flatMap((g) => g.items)) {
    const comp = item.compositionId ? compositions.find((c) => c.id === item.compositionId) : undefined
    if (comp) {
      // serviço composto: distribui o custo pelos tipos dos insumos
      for (const input of comp.inputs) acc[input.type] += item.quantity * input.coefficient * input.unitPrice
    } else {
      acc[item.type] += itemTotal(item, compositions)
    }
  }
  return acc
}

export const resourceTypeLabel: Record<ResourceType, string> = {
  service: 'Serviço',
  material: 'Material',
  labor: 'Mão de obra',
  equipment: 'Equipamento',
}
