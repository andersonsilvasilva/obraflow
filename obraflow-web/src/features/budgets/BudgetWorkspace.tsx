import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Outlet, useOutletContext, useParams } from 'react-router-dom'
import { QueryBoundary } from '@/components/feedback/QueryBoundary'
import { budgetsService } from '@/services'
import type { BudgetGroup, Composition } from '@/types'

export interface BudgetWorkspaceContext {
  budgetId: string
  groups: BudgetGroup[]
  setGroups: React.Dispatch<React.SetStateAction<BudgetGroup[]>>
  bdi: number
  discount: number
  setDiscount: (v: number) => void
  setBdi: (v: number) => void
  compositions: Composition[]
}

export const useBudgetWorkspace = () => useOutletContext<BudgetWorkspaceContext>()

/**
 * Mantém o estado local do orçamento em edição compartilhado entre as telas
 * de preenchimento e finalização (apenas demonstrativo, sem persistência).
 */
export function BudgetWorkspace() {
  const { id = '' } = useParams()
  const groupsQuery = useQuery({ queryKey: ['budget-groups', id], queryFn: () => budgetsService.groups(id) })
  const compsQuery = useQuery({ queryKey: ['compositions'], queryFn: budgetsService.compositions })

  return (
    <QueryBoundary query={groupsQuery}>
      {(initial) => (
        <QueryBoundary query={compsQuery}>
          {(compositions) => <Inner budgetId={id} initial={initial} compositions={compositions} />}
        </QueryBoundary>
      )}
    </QueryBoundary>
  )
}

function Inner({ budgetId, initial, compositions }: { budgetId: string; initial: BudgetGroup[]; compositions: Composition[] }) {
  const [groups, setGroups] = useState(initial)
  const [bdi, setBdi] = useState(25)
  const [discount, setDiscount] = useState(0)
  return <Outlet context={{ budgetId, groups, setGroups, bdi, setBdi, discount, setDiscount, compositions } satisfies BudgetWorkspaceContext} />
}
