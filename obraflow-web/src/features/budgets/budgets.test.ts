import { describe, expect, it } from 'vitest'
import { workflowActions } from './api'
import { orderedGroups } from './BudgetTabs'

describe('ações do fluxo por status e permissão (espelho do BudgetWorkflow)', () => {
  const all = { edit: true, approve: true }

  it('rascunho: só enviar (quem edita)', () => {
    expect(workflowActions('draft', all)).toEqual(['submit'])
    expect(workflowActions('draft', { edit: false, approve: true })).toEqual([])
  })

  it('em revisão: devolver (quem edita), aprovar e rejeitar (quem aprova)', () => {
    expect(workflowActions('in_review', all)).toEqual(['withdraw', 'approve', 'reject'])
    expect(workflowActions('in_review', { edit: false, approve: true })).toEqual(['approve', 'reject'])
    expect(workflowActions('in_review', { edit: true, approve: false })).toEqual(['withdraw'])
  })

  it('rejeitada: reabrir; aprovada/substituída: nenhuma (nova versão é outra ação)', () => {
    expect(workflowActions('rejected', all)).toEqual(['reopen'])
    expect(workflowActions('approved', all)).toEqual([])
    expect(workflowActions('superseded', all)).toEqual([])
    expect(workflowActions(undefined, all)).toEqual([])
  })
})

describe('etapas em ordem de árvore', () => {
  it('ordena por pai, ordem e id, com a profundidade', () => {
    const g = (id: number, parent: number | null, sort: number) => ({ id, parent_id: parent, code: String(id), name: `G${id}`, sort_order: sort })
    const tree = orderedGroups([g(3, 1, 2), g(1, null, 1), g(2, 1, 1), g(4, null, 2), g(5, 2, 1)])
    expect(tree.map((t) => [t.group.id, t.depth])).toEqual([
      [1, 0],
      [2, 1],
      [5, 2],
      [3, 1],
      [4, 0],
    ])
  })
})
