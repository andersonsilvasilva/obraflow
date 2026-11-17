import { describe, expect, it } from 'vitest'
import { filterNavigation, navigation, type NavNode } from '@/app/navigation'
import { contractActions, partyName } from './api'

describe('contratos (5A)', () => {
  it('ações do ciclo de vida por status (espelho de ContractWorkflow; a API decide)', () => {
    expect(contractActions('draft')).toEqual(['activate', 'cancel'])
    expect(contractActions('active')).toEqual(['suspend', 'complete', 'cancel'])
    expect(contractActions('suspended')).toEqual(['resume', 'complete', 'cancel'])
    expect(contractActions('completed')).toEqual([])
    expect(contractActions('canceled')).toEqual([])
  })

  it('parte do contrato conforme o tipo', () => {
    expect(partyName({ kind: 'customer', customer: { uuid: 'x', name: 'DEMO Cliente' }, supplier: null })).toBe('DEMO Cliente')
    expect(partyName({ kind: 'supplier', customer: null, supplier: { uuid: 'y', name: 'DEMO Empreiteira' } })).toBe('DEMO Empreiteira')
    expect(partyName({ kind: 'supplier', customer: null, supplier: null })).toBe('—')
  })

  it('contratos ficam dentro da obra (A-45): sem item "Contratos" solto no menu', () => {
    const labels = (nodes: NavNode[]): string[] => nodes.flatMap((n) => [n.label, ...labels(n.children ?? [])])
    expect(labels(filterNavigation(navigation, () => true))).not.toContain('Contratos')
  })
})
