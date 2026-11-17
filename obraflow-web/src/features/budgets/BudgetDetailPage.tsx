import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, CopyPlus, Pencil, RotateCcw, Send, Undo2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '@/app/providers/ToastProvider'
import { Panel } from '@/components/data-display/DataTable'
import { StatCard, StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { ConfirmDialog } from '@/components/feedback/Modal'
import { ErrorState, LoadingState } from '@/components/feedback/states'
import { SelectInput } from '@/components/forms/fields'
import { Tabs } from '@/components/layout/Tabs'
import { Button } from '@/components/ui/button'
import { EntityFormModal, type FieldDef } from '@/features/registry/components/EntityFormModal'
import { getErrorMessage } from '@/lib/api-error'
import { formatCurrency } from '@/utils/format'
import { budgetsApi, budgetStatusLabel, budgetStatusTone, regimeLabel, workflowActions, type Group, type Item, type WorkflowAction } from './api'
import { AbcTab, BdiTab, CompositionModal, HistoryTab, RejectDialog, SheetTab } from './BudgetTabs'
import { orderedGroups } from './tree'
import { ReferencePicker } from './ReferencePicker'

type Dialog =
  | { kind: 'group'; row?: Group }
  | { kind: 'item'; row?: Item }
  | { kind: 'reference'; groupId?: number }
  | { kind: 'composition'; row: Item }
  | { kind: 'reject' }
  | { kind: 'confirm'; title: string; description: string; run: () => Promise<unknown> }

const actionMeta: Record<WorkflowAction, { label: string; icon: typeof Send; variant: 'default' | 'outline' | 'destructive' }> = {
  submit: { label: 'Enviar para revisão', icon: Send, variant: 'default' },
  withdraw: { label: 'Devolver para rascunho', icon: Undo2, variant: 'outline' },
  approve: { label: 'Aprovar', icon: CheckCircle2, variant: 'default' },
  reject: { label: 'Rejeitar', icon: XCircle, variant: 'destructive' },
  reopen: { label: 'Reabrir', icon: RotateCcw, variant: 'outline' },
}

/** Orçamento: planilha (EAP + itens), BDI/indiretos, Curva ABC e histórico; fluxo de aprovação no cabeçalho. */
export function BudgetDetailPage() {
  const { uuid = '' } = useParams()
  const client = useQueryClient()
  const { notify } = useToast()
  const [tab, setTab] = useState('planilha')
  const [selected, setSelected] = useState<number | null>(null)
  const [dialog, setDialog] = useState<Dialog | null>(null)

  const budget = useQuery({ queryKey: ['budget', uuid], queryFn: () => budgetsApi.get(uuid) })
  const current = budget.data?.current_version?.number
  const number = selected ?? current ?? 1
  const version = useQuery({ queryKey: ['budget-version', uuid, number], queryFn: () => budgetsApi.version(uuid, number), enabled: !!budget.data })

  const refresh = () => {
    for (const k of [['budget', uuid], ['budget-version', uuid], ['budget-abc', uuid], ['budget-history', uuid], ['budgets']]) client.invalidateQueries({ queryKey: k })
  }
  const act = async (fn: () => Promise<unknown>, ok: string) => {
    try {
      await fn()
      notify('success', ok)
      setDialog(null)
      refresh()
    } catch (e) {
      notify('error', 'Não foi possível concluir', getErrorMessage(e))
    }
  }

  if (budget.isPending) return <LoadingState />
  if (budget.isError) return <ErrorState error={budget.error} onRetry={() => budget.refetch()} />
  const b = budget.data
  const v = version.data
  const isCurrent = number === current
  const editable = !!(isCurrent && v && v.status === 'draft' && !v.locked && b.abilities?.edit)
  const actions = isCurrent ? workflowActions(b.current_version?.status, b.abilities) : []
  const canNewVersion = isCurrent && b.abilities?.edit && (b.current_version?.status === 'approved' || b.current_version?.status === 'rejected')

  const runAction = (a: WorkflowAction) =>
    a === 'reject'
      ? setDialog({ kind: 'reject' })
      : setDialog({
          kind: 'confirm',
          title: `${actionMeta[a].label}?`,
          description: a === 'approve' ? 'A versão será recalculada e BLOQUEADA (somente leitura). Para alterar depois, crie uma nova versão.' : 'Confirme a mudança de status.',
          run: () => budgetsApi.workflow(uuid, a),
        })

  const groupFields: FieldDef[] = [
    { name: 'code', label: 'Código', required: true, placeholder: 'Ex.: 1.2' },
    { name: 'name', label: 'Nome', required: true },
    {
      name: 'parent_id',
      label: 'Etapa superior',
      type: 'select',
      placeholder: 'Nenhuma (etapa principal)',
      help: 'Até 4 níveis.',
      options: orderedGroups(v?.groups ?? [])
        .filter(({ depth, group }) => depth < 3 && (dialog?.kind !== 'group' || group.id !== dialog.row?.id))
        .map(({ group, depth }) => ({ value: String(group.id), label: `${'— '.repeat(depth)}${group.code} ${group.name}` })),
    },
  ]
  const itemFields: FieldDef[] = [
    { name: 'group_id', label: 'Etapa', type: 'select', required: true, options: (v?.groups ?? []).map((g) => ({ value: String(g.id), label: `${g.code} — ${g.name}` })) },
    { name: 'code', label: 'Código', required: true },
    { name: 'description', label: 'Descrição', type: 'textarea', required: true, full: true },
    { name: 'unit_code', label: 'Unidade', required: true, placeholder: 'm², m³, un, vb…' },
    { name: 'quantity', label: 'Quantidade', type: 'number', step: '0.0001', required: true },
    { name: 'unit_cost', label: 'Custo unitário (R$)', type: 'number', step: '0.01', required: true, help: 'Alterar o custo de um item oficial o torna "ajustado".' },
    { name: 'bdi_percent', label: 'BDI do item (%)', type: 'number', step: '0.0001', help: 'Vazio = herda da etapa ou o global.' },
    { name: 'discount_percent', label: 'Desconto do item (%)', type: 'number', step: '0.0001' },
  ]

  const t = v?.totals
  return (
    <div>
      <Link to="/orcamentos" className="text-muted-foreground hover:text-primary mb-2 inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="size-4" /> Orçamentos
      </Link>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3 border-b pb-4">
        <div>
          <p className="text-muted-foreground text-sm">
            {b.code} · {b.company?.legal_name}
            {b.project && ` · Obra ${b.project.code}`}
          </p>
          <h1 className="text-2xl font-semibold tracking-tight">{b.title}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <StatusBadge tone={budgetStatusTone[b.status]}>{budgetStatusLabel[b.status]}</StatusBadge>
            <span className="text-muted-foreground">
              {regimeLabel[b.social_charges_regime]}
              {b.reference_dataset && ` · SINAPI ${b.reference_dataset.state} ${b.reference_dataset.competency}`}
            </span>
            <label className="sr-only" htmlFor="version-select">
              Versão
            </label>
            <SelectInput id="version-select" className="w-auto" value={number} onChange={(e) => setSelected(Number(e.target.value))}>
              {(b.versions ?? []).map((ver) => (
                <option key={ver.number} value={ver.number}>
                  v{ver.number} · {budgetStatusLabel[ver.status]}
                  {ver.number === current ? ' (atual)' : ''}
                </option>
              ))}
            </SelectInput>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {actions.map((a) => {
            const { label, icon: Icon, variant } = actionMeta[a]
            return (
              <Button key={a} variant={variant} size="lg" onClick={() => runAction(a)}>
                <Icon className="size-4" /> {label}
              </Button>
            )
          })}
          {canNewVersion && (
            <Button variant="outline" size="lg" onClick={() => setDialog({ kind: 'confirm', title: 'Criar nova versão?', description: 'A versão atual é copiada para uma nova versão em rascunho.', run: () => budgetsApi.newVersion(uuid) })}>
              <CopyPlus className="size-4" /> Nova versão
            </Button>
          )}
        </div>
      </div>

      {v?.locked && (
        <Alert tone="info" className="mb-4">
          Versão {v.number} {v.status === 'superseded' ? 'substituída' : 'aprovada'} em {v.approved_at ? new Date(v.approved_at).toLocaleString('pt-BR') : '—'}: somente leitura.
        </Alert>
      )}
      {v && !v.locked && isCurrent && v.status !== 'draft' && (
        <Alert tone="warning" className="mb-4">
          Versão {budgetStatusLabel[v.status].toLowerCase()}: devolva ou reabra para rascunho antes de editar.
        </Alert>
      )}

      {t && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard label="Custo direto" value={formatCurrency(Number(t.direct_cost))} />
          <StatCard label="BDI" value={formatCurrency(Number(t.bdi_amount))} />
          <StatCard label="Custos indiretos" value={formatCurrency(Number(t.indirect_cost))} />
          <StatCard label="Descontos" value={formatCurrency(Number(t.items_discount) + Number(t.global_discount))} />
          <StatCard label="Preço final" value={formatCurrency(Number(t.final_price))} hint={`${t.item_count} item(ns)`} />
        </div>
      )}

      <Panel className="p-4">
        {version.isPending ? (
          <LoadingState />
        ) : version.isError ? (
          <ErrorState error={version.error} onRetry={() => version.refetch()} />
        ) : (
          <Tabs
            label="Seções do orçamento"
            active={tab}
            onChange={setTab}
            tabs={[
              { id: 'planilha', label: 'Planilha' },
              { id: 'bdi', label: 'BDI e indiretos' },
              { id: 'abc', label: 'Curva ABC' },
              { id: 'historico', label: 'Histórico' },
            ]}
          >
            {tab === 'planilha' && (
              <SheetTab
                version={v!}
                editable={editable}
                onAddGroup={() => setDialog({ kind: 'group' })}
                onEditGroup={(g) => setDialog({ kind: 'group', row: g })}
                onDeleteGroup={(g) => setDialog({ kind: 'confirm', title: `Excluir a etapa ${g.code}?`, description: 'Só etapas sem itens e sem subetapas podem ser excluídas.', run: () => budgetsApi.groups.remove(uuid, number, g.id) })}
                onAddItem={() => setDialog({ kind: 'item' })}
                onAddReference={(groupId) => setDialog({ kind: 'reference', groupId })}
                onEditItem={(i) => setDialog({ kind: 'item', row: i })}
                onDeleteItem={(i) => setDialog({ kind: 'confirm', title: `Excluir o item ${i.code}?`, description: 'A composição copiada e os insumos do item também são removidos.', run: () => budgetsApi.items.remove(uuid, number, i.id) })}
                onComposition={(i) => setDialog({ kind: 'composition', row: i })}
              />
            )}
            {tab === 'bdi' && <BdiTab key={`${number}-${v!.bdi.length}`} uuid={uuid} version={v!} editable={editable} onChanged={refresh} />}
            {tab === 'abc' && <AbcTab uuid={uuid} number={number} />}
            {tab === 'historico' && <HistoryTab uuid={uuid} />}
          </Tabs>
        )}
      </Panel>

      {dialog?.kind === 'group' && (
        <EntityFormModal
          title={dialog.row ? 'Editar etapa' : 'Nova etapa'}
          mode={dialog.row ? 'edit' : 'create'}
          size="md"
          fields={groupFields}
          lookups={{}}
          initial={dialog.row ? { code: dialog.row.code, name: dialog.row.name, parent_id: dialog.row.parent_id ? String(dialog.row.parent_id) : '' } : {}}
          onClose={() => setDialog(null)}
          onSubmit={(body) => (dialog.row ? budgetsApi.groups.update(uuid, number, dialog.row.id, body) : budgetsApi.groups.create(uuid, number, body))}
          onSaved={() => (notify('success', 'Etapa salva'), setDialog(null), refresh())}
        />
      )}
      {dialog?.kind === 'item' && (
        <EntityFormModal
          title={dialog.row ? `Editar item ${dialog.row.code}` : 'Novo item manual'}
          mode={dialog.row ? 'edit' : 'create'}
          size="xl"
          fields={itemFields}
          lookups={{}}
          initial={
            dialog.row
              ? {
                  group_id: String(dialog.row.group_id),
                  code: dialog.row.code,
                  description: dialog.row.description,
                  unit_code: dialog.row.unit_code,
                  quantity: dialog.row.quantity,
                  unit_cost: dialog.row.unit_cost,
                  bdi_percent: dialog.row.bdi_percent ?? '',
                  discount_percent: dialog.row.discount_percent,
                }
              : { group_id: v?.groups[0] ? String(v.groups[0].id) : '' }
          }
          onClose={() => setDialog(null)}
          onSubmit={(body) => {
            if (!body.discount_percent) body.discount_percent = '0'
            return dialog.row ? budgetsApi.items.update(uuid, number, dialog.row.id, body) : budgetsApi.items.create(uuid, number, body)
          }}
          onSaved={() => (notify('success', 'Item salvo'), setDialog(null), refresh())}
        />
      )}
      {dialog?.kind === 'reference' && v && (
        <ReferencePicker
          uuid={uuid}
          number={number}
          groups={v.groups}
          datasetId={b.reference_dataset?.id ?? null}
          regime={v.social_charges_regime}
          initialGroupId={dialog.groupId}
          onClose={() => setDialog(null)}
          onAdded={(keepOpen) => {
            notify('success', 'Item adicionado da base')
            refresh()
            if (!keepOpen) setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'composition' && <CompositionModal uuid={uuid} number={number} item={dialog.row} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'reject' && <RejectDialog onClose={() => setDialog(null)} onConfirm={(c) => act(() => budgetsApi.workflow(uuid, 'reject', c), 'Versão rejeitada')} />}
      <ConfirmDialog
        open={dialog?.kind === 'confirm'}
        title={dialog?.kind === 'confirm' ? dialog.title : ''}
        description={dialog?.kind === 'confirm' ? dialog.description : ''}
        onCancel={() => setDialog(null)}
        onConfirm={() => dialog?.kind === 'confirm' && act(dialog.run, 'Concluído')}
      />
      {b.abilities && !b.abilities.edit && (
        <p className="text-muted-foreground mt-3 flex items-center gap-1 text-xs">
          <Pencil className="size-3" /> Somente leitura para o seu perfil.
        </p>
      )}
    </div>
  )
}
