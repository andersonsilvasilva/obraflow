import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Paperclip, Plus } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '@/app/providers/ToastProvider'
import { StatusBadge } from '@/components/data-display/indicators'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { EntityFormModal } from '@/features/registry/components/EntityFormModal'
import { formatCurrency, formatDateOrDash } from '@/utils/format'
import { contractsApi, contractStatusLabel, contractStatusTone, partyName } from './api'
import { contractFields, usePartyLookups } from './form'

interface Props {
  project: { uuid: string; company?: { uuid: string } }
  /** nível edit na obra (D-P24) */
  canEditProject: boolean
}

/**
 * Aba Contratos da obra (A-45, referência: Brickup): os contratos e aditivos da obra ficam aqui, com os arquivos
 * assinados. Clique no contrato para itens, aditivos, documentos e histórico.
 */
export function ProjectContractsTab({ project, canEditProject }: Props) {
  const { can } = useAuth()
  const { notify } = useToast()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [creating, setCreating] = useState(false)
  const lookups = usePartyLookups(project.company?.uuid)
  const canCreate = canEditProject && can('contracts.manage')

  const list = useQuery({
    queryKey: ['contracts', 'project', project.uuid],
    queryFn: () => contractsApi.list({ project_uuid: project.uuid, per_page: 100 }),
    enabled: can('contracts.view'),
  })

  if (!can('contracts.view')) return <EmptyState title="Sem acesso aos contratos" description="Peça a permissão de consulta de contratos ao administrador." />

  const rows = list.data?.data ?? []
  const open = (uuid: string) => navigate(`/obras/${project.uuid}/contratos/${uuid}`)

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-sm">Contratos e aditivos da obra, com os arquivos assinados.</p>
        <Button size="lg" disabled={!canCreate} onClick={() => setCreating(true)}>
          <Plus className="size-4" /> Adicionar contrato
        </Button>
      </div>

      {list.isPending ? (
        <LoadingState />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState title="Nenhum contrato nesta obra" description="Adicione o contrato com o cliente ou com um fornecedor/empreiteiro." />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <caption className="sr-only">Contratos da obra</caption>
            <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
              <tr>
                <th scope="col" className="px-3 py-2">Contrato</th>
                <th scope="col" className="px-3 py-2">Descrição</th>
                <th scope="col" className="px-3 py-2">Parte</th>
                <th scope="col" className="px-3 py-2 text-right">Valor atual</th>
                <th scope="col" className="px-3 py-2">Término</th>
                <th scope="col" className="px-3 py-2">Status</th>
                <th scope="col" className="px-3 py-2">Arquivos</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((r) => (
                <tr key={r.uuid} className="hover:bg-primary-soft/40 cursor-pointer" onClick={() => open(r.uuid)}>
                  <td className="px-3 py-2 font-medium">
                    <button type="button" className="hover:text-primary text-left underline-offset-2 hover:underline" onClick={() => open(r.uuid)}>
                      {r.code}
                    </button>
                    <p className="text-muted-foreground text-xs">{r.kind === 'customer' ? 'Com cliente' : 'Com fornecedor'}</p>
                  </td>
                  <td className="max-w-80 px-3 py-2">
                    <p className="line-clamp-2">{r.object}</p>
                  </td>
                  <td className="px-3 py-2">{partyName(r)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(Number(r.current_value))}</td>
                  <td className="px-3 py-2 tabular-nums">{formatDateOrDash(r.current_end_date)}</td>
                  <td className="px-3 py-2">
                    <StatusBadge tone={contractStatusTone[r.status]}>{contractStatusLabel[r.status]}</StatusBadge>
                  </td>
                  <td className="text-muted-foreground px-3 py-2">
                    <span className="inline-flex items-center gap-1">
                      <Paperclip className="size-3.5" aria-hidden /> {r.documents_count ?? 0}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {creating && (
        <EntityFormModal
          title="Adicionar contrato"
          size="xl"
          mode="create"
          fields={contractFields}
          lookups={lookups}
          initial={{ kind: 'customer' }}
          onClose={() => setCreating(false)}
          onSubmit={async (b) => {
            const created = await contractsApi.create({ ...b, project_uuid: project.uuid })
            open(created.uuid)
          }}
          onSaved={() => {
            notify('success', 'Contrato criado. Inclua os itens ou gere a partir do orçamento aprovado.')
            setCreating(false)
            client.invalidateQueries({ queryKey: ['contracts'] })
          }}
        />
      )}
    </div>
  )
}
