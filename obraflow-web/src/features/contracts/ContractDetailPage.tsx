import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, Check, FileStack, Pencil, Plus, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '@/app/providers/ToastProvider'
import { StatusBadge } from '@/components/data-display/indicators'
import { Panel } from '@/components/data-display/DataTable'
import { Alert } from '@/components/feedback/Alert'
import { ConfirmDialog, Modal } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextArea, TextInput } from '@/components/forms/fields'
import { Tabs } from '@/components/layout/Tabs'
import { Button } from '@/components/ui/button'
import { budgetsApi } from '@/features/budgets/api'
import { EntityFormModal, type FieldDef } from '@/features/registry/components/EntityFormModal'
import { getErrorMessage } from '@/lib/api-error'
import { formatCurrency, formatDateOrDash } from '@/utils/format'
import { AttachmentsPanel } from './AttachmentsPanel'
import {
  addendumKindLabel,
  addendumStatusLabel,
  contractActions,
  contractsApi,
  contractStatusLabel,
  contractStatusTone,
  partyName,
  type Addendum,
  type Contract,
  type ContractAction,
  type ContractItem,
} from './api'
import { contractFields, usePartyLookups } from './form'

const money = (v: string | number) => formatCurrency(Number(v))
const qty = (v: string) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 4 })
const today = () => new Date().toISOString().slice(0, 10)

const actionLabel: Record<ContractAction, string> = { activate: 'Ativar', suspend: 'Suspender', resume: 'Retomar', complete: 'Concluir', cancel: 'Cancelar contrato' }

/** Detalhe do contrato (Fase 5A): resumo, itens, aditivos, arquivos e histórico. A API decide; a tela só habilita. */
export function ContractDetailPage() {
  const { uuid: projectUuid = '', contractUuid = '' } = useParams()
  const { notify } = useToast()
  const client = useQueryClient()
  const [tab, setTab] = useState('itens')
  const [action, setAction] = useState<ContractAction | null>(null)
  const [editing, setEditing] = useState(false)

  const query = useQuery({ queryKey: ['contract', contractUuid], queryFn: () => contractsApi.get(contractUuid) })
  const contract = query.data
  const lookups = usePartyLookups(contract?.company?.uuid)

  const refresh = () => {
    client.invalidateQueries({ queryKey: ['contract', contractUuid] })
    client.invalidateQueries({ queryKey: ['contract-items', contractUuid] })
    client.invalidateQueries({ queryKey: ['contract-addenda', contractUuid] })
    client.invalidateQueries({ queryKey: ['contract-history', contractUuid] })
    client.invalidateQueries({ queryKey: ['contracts'] })
  }

  if (query.isPending) return <LoadingState />
  if (query.isError || !contract) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const canEdit = contract.abilities?.edit ?? false
  const draft = contract.status === 'draft'
  const addendaDelta = Number(contract.current_value) - Number(contract.original_value)
  // depois de assinado, só observações e regra de reajuste (D-P48)
  const editFields: FieldDef[] = draft ? contractFields : contractFields.filter((f) => ['readjustment_rule', 'notes'].includes(f.name)).map((f) => ({ ...f, more: false }))

  return (
    <div>
      <Link to={`/obras/${projectUuid}?aba=contratos`} className="text-muted-foreground hover:text-primary mb-2 inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="size-4" /> {contract.project ? `${contract.project.code} — ${contract.project.name}` : 'Obra'}
      </Link>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3 border-b pb-4">
        <div className="min-w-0">
          <p className="text-muted-foreground text-sm">{contract.kind === 'customer' ? 'Contrato com cliente' : 'Contrato com fornecedor/empreiteiro'}</p>
          <h1 className="text-2xl font-semibold tracking-tight">
            {contract.code} · {partyName(contract)}
          </h1>
          <p className="mt-1 max-w-3xl text-sm whitespace-pre-line">{contract.object}</p>
          <div className="mt-2">
            <StatusBadge tone={contractStatusTone[contract.status]}>{contractStatusLabel[contract.status]}</StatusBadge>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {contractActions(contract.status).map((a) => (
            <Button key={a} variant={a === 'activate' ? 'default' : 'outline'} size="lg" disabled={!canEdit} onClick={() => setAction(a)}>
              {actionLabel[a]}
            </Button>
          ))}
          {['draft', 'active', 'suspended'].includes(contract.status) && (
            <Button variant="outline" size="lg" disabled={!canEdit} onClick={() => setEditing(true)}>
              <Pencil className="size-4" /> Editar
            </Button>
          )}
        </div>
      </div>

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Summary label="Valor original" value={money(contract.original_value)} hint={draft ? 'fixado ao ativar' : contract.signed_at ? `assinado em ${formatDateOrDash(contract.signed_at)}` : undefined} />
        <Summary label="Aditivos" value={money(addendaDelta)} hint={addendaDelta === 0 ? 'nenhum valor aditivado' : undefined} />
        <Summary label="Valor atual" value={money(contract.current_value)} strong />
        <Summary
          label="Prazo"
          value={`${formatDateOrDash(contract.starts_at)} a ${formatDateOrDash(contract.current_end_date)}`}
          hint={contract.original_end_date && contract.original_end_date !== contract.current_end_date ? `término original ${formatDateOrDash(contract.original_end_date)}` : undefined}
        />
        <Summary label="Retenção" value={`${Number(contract.retention_percent).toLocaleString('pt-BR')}%`} />
        <Summary label="Orçamento de origem" value={contract.budget ? `${contract.budget.code} (v${contract.budget.version})` : '—'} />
        <Summary label="Medido" value="—" hint="em breve: medições (5B)" />
        {contract.readjustment_rule && <Summary label="Reajuste" value={contract.readjustment_rule} small />}
      </div>

      <Panel className="p-4">
        <Tabs
          tabs={[
            { id: 'itens', label: `Itens (${contract.items_count ?? 0})` },
            { id: 'aditivos', label: 'Aditivos' },
            { id: 'arquivos', label: `Arquivos (${contract.documents_count ?? 0})` },
            { id: 'historico', label: 'Histórico' },
          ]}
          active={tab}
          onChange={setTab}
          label="Seções do contrato"
        >
          {tab === 'itens' && <ItemsTab contract={contract} canEdit={canEdit} onChanged={refresh} />}
          {tab === 'aditivos' && <AddendaTab contract={contract} canEdit={canEdit} onChanged={refresh} />}
          {tab === 'arquivos' && <AttachmentsPanel type="contract" linkKey={contract.uuid} canEdit={canEdit} suggestedTitle={`Contrato ${contract.code} assinado`} />}
          {tab === 'historico' && <HistoryTab uuid={contract.uuid} />}
        </Tabs>
      </Panel>

      {action && (
        <ActionDialog
          contract={contract}
          action={action}
          onClose={() => setAction(null)}
          onDone={(msg) => {
            notify('success', msg)
            setAction(null)
            refresh()
          }}
        />
      )}
      {editing && (
        <EntityFormModal
          title={`Editar contrato ${contract.code}`}
          size="xl"
          mode="edit"
          fields={editFields}
          lookups={lookups}
          initial={toForm(contract)}
          onClose={() => setEditing(false)}
          onSubmit={(b) => contractsApi.update(contract.uuid, b)}
          onSaved={() => {
            notify('success', 'Contrato atualizado.')
            setEditing(false)
            refresh()
          }}
        />
      )}
    </div>
  )
}

function toForm(c: Contract): Record<string, string> {
  const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
  return {
    kind: c.kind, customer_uuid: c.customer?.uuid ?? '', supplier_uuid: c.supplier?.uuid ?? '', code: c.code, object: c.object,
    starts_at: s(c.starts_at), original_end_date: s(c.original_end_date), signed_at: s(c.signed_at), retention_percent: s(Number(c.retention_percent)),
    readjustment_rule: s(c.readjustment_rule), notes: s(c.notes),
  }
}

function Summary({ label, value, hint, strong, small }: { label: string; value: string; hint?: string; strong?: boolean; small?: boolean }) {
  return (
    <div className="bg-card rounded-lg border p-3">
      <p className="text-muted-foreground text-xs">{label}</p>
      <p className={small ? 'mt-1 text-sm' : strong ? 'text-primary mt-1 text-lg font-semibold tabular-nums' : 'mt-1 text-lg font-semibold tabular-nums'}>{value}</p>
      {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
    </div>
  )
}

function ActionDialog({ contract, action, onClose, onDone }: { contract: Contract; action: ContractAction; onClose: () => void; onDone: (msg: string) => void }) {
  const [reason, setReason] = useState('')
  const [signedAt, setSignedAt] = useState(contract.signed_at ?? today())
  const needsReason = action === 'suspend' || action === 'cancel'
  const run = useMutation({
    mutationFn: () => contractsApi.workflow(contract.uuid, action, action === 'activate' ? { signed_at: signedAt } : { reason: reason || undefined }),
    onSuccess: () => onDone(`Contrato: ${actionLabel[action].toLowerCase()} — feito.`),
  })
  const text: Record<ContractAction, string> = {
    activate: 'Ao ativar, o valor original fica fixado e os itens só mudam por aditivo.',
    suspend: 'O contrato fica suspenso (não recebe aditivos) até ser retomado.',
    resume: 'O contrato volta a ficar ativo.',
    complete: 'O contrato é encerrado. Não poderá mais ser alterado.',
    cancel: 'O contrato é cancelado (não é excluído: fica no histórico).',
  }
  return (
    <Modal
      open
      onClose={onClose}
      title={`${actionLabel[action]} ${contract.code}?`}
      description={text[action]}
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Voltar
          </Button>
          <Button size="lg" variant={action === 'cancel' ? 'destructive' : 'default'} disabled={run.isPending || (needsReason && !reason.trim()) || (action === 'activate' && !signedAt)} onClick={() => run.mutate()}>
            {actionLabel[action]}
          </Button>
        </>
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      {action === 'activate' && (
        <FormField label="Data de assinatura" required>
          <TextInput type="date" value={signedAt} onChange={(e) => setSignedAt(e.target.value)} />
        </FormField>
      )}
      {needsReason && (
        <FormField label="Motivo" required>
          <TextArea value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} autoFocus />
        </FormField>
      )}
    </Modal>
  )
}

// ------------------------------------------------------------------ itens

const itemFields: FieldDef[] = [
  { name: 'code', label: 'Código' },
  { name: 'unit_code', label: 'Unidade', required: true, placeholder: 'm², m³, un, VB…' },
  { name: 'description', label: 'Descrição', type: 'textarea', required: true, full: true },
  { name: 'quantity', label: 'Quantidade', type: 'number', step: '0.0001', required: true },
  { name: 'unit_price', label: 'Preço unitário (R$)', type: 'number', step: '0.01', required: true },
]

function ItemsTab({ contract, canEdit, onChanged }: { contract: Contract; canEdit: boolean; onChanged: () => void }) {
  const { notify } = useToast()
  const draft = contract.status === 'draft'
  const editable = canEdit && draft
  const items = useQuery({ queryKey: ['contract-items', contract.uuid], queryFn: () => contractsApi.items.list(contract.uuid) })
  const [form, setForm] = useState<{ row?: ContractItem } | null>(null)
  const [removing, setRemoving] = useState<ContractItem | null>(null)
  const [generating, setGenerating] = useState(false)

  const rows = items.data ?? []
  const withAddenda = rows.some((r) => r.original_quantity !== r.quantity || r.addendum !== null)

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-sm">
          {draft ? 'Rascunho: inclua os itens à mão ou gere a partir do orçamento aprovado.' : 'Contrato assinado: os itens só mudam por aditivo.'}
        </p>
        {editable && (
          <div className="flex gap-2">
            <Button variant="outline" size="lg" disabled={rows.length > 0} title={rows.length > 0 ? 'Remova os itens para gerar do orçamento' : undefined} onClick={() => setGenerating(true)}>
              <FileStack className="size-4" /> Gerar do orçamento aprovado
            </Button>
            <Button size="lg" onClick={() => setForm({})}>
              <Plus className="size-4" /> Adicionar item
            </Button>
          </div>
        )}
      </div>
      {items.isPending ? (
        <LoadingState />
      ) : items.isError ? (
        <ErrorState error={items.error} onRetry={() => items.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState title="Sem itens" description="A planilha contratual define o que será medido." />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <caption className="sr-only">Itens do contrato</caption>
            <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
              <tr>
                <th scope="col" className="px-3 py-2">Código</th>
                <th scope="col" className="px-3 py-2">Descrição</th>
                <th scope="col" className="px-3 py-2">Un.</th>
                {withAddenda && <th scope="col" className="px-3 py-2 text-right">Qtd. original</th>}
                <th scope="col" className="px-3 py-2 text-right">{withAddenda ? 'Qtd. atual' : 'Quantidade'}</th>
                <th scope="col" className="px-3 py-2 text-right">Preço unit.</th>
                <th scope="col" className="px-3 py-2 text-right">Total</th>
                {editable && <th scope="col" className="w-20 px-3 py-2"><span className="sr-only">Ações</span></th>}
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2 font-mono text-xs">{r.code ?? '—'}</td>
                  <td className="px-3 py-2">
                    {r.description}
                    {r.addendum !== null && <span className="bg-primary-soft text-primary ml-2 rounded px-1.5 py-0.5 text-[0.7rem]">Aditivo {r.addendum}</span>}
                  </td>
                  <td className="px-3 py-2">{r.unit_code}</td>
                  {withAddenda && <td className="text-muted-foreground px-3 py-2 text-right tabular-nums">{qty(r.original_quantity)}</td>}
                  <td className="px-3 py-2 text-right tabular-nums">{qty(r.quantity)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{money(r.unit_price)}</td>
                  <td className="px-3 py-2 text-right font-medium tabular-nums">{money(r.total)}</td>
                  {editable && (
                    <td className="px-3 py-2">
                      <div className="flex gap-1">
                        <Button variant="ghost" size="icon-sm" aria-label={`Editar ${r.description}`} onClick={() => setForm({ row: r })}>
                          <Pencil />
                        </Button>
                        <Button variant="ghost" size="icon-sm" aria-label={`Excluir ${r.description}`} onClick={() => setRemoving(r)}>
                          <Trash2 />
                        </Button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="bg-muted/40 font-semibold">
                <td colSpan={withAddenda ? 6 : 5} className="px-3 py-2 text-right">
                  Total do contrato
                </td>
                <td className="px-3 py-2 text-right tabular-nums">{money(contract.current_value)}</td>
                {editable && <td />}
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {form && (
        <EntityFormModal
          title={form.row ? 'Editar item' : 'Adicionar item'}
          mode={form.row ? 'edit' : 'create'}
          fields={itemFields}
          lookups={{}}
          initial={form.row ? { code: form.row.code ?? '', unit_code: form.row.unit_code, description: form.row.description, quantity: form.row.quantity, unit_price: form.row.unit_price } : {}}
          onClose={() => setForm(null)}
          onSubmit={(b) => (form.row ? contractsApi.items.update(contract.uuid, form.row.id, b) : contractsApi.items.create(contract.uuid, b))}
          onSaved={() => {
            notify('success', 'Item salvo.')
            setForm(null)
            onChanged()
          }}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Excluir o item?"
        description={removing?.description ?? ''}
        confirmLabel="Excluir"
        destructive
        onConfirm={() =>
          removing &&
          contractsApi.items
            .remove(contract.uuid, removing.id)
            .then(() => (setRemoving(null), onChanged()))
            .catch((e) => notify('error', getErrorMessage(e)))
        }
        onCancel={() => setRemoving(null)}
      />
      {generating && <GenerateDialog contract={contract} onClose={() => setGenerating(false)} onDone={() => (setGenerating(false), onChanged())} />}
    </div>
  )
}

function GenerateDialog({ contract, onClose, onDone }: { contract: Contract; onClose: () => void; onDone: () => void }) {
  const budgets = useQuery({
    queryKey: ['budgets', 'approved', contract.project?.uuid],
    queryFn: () => budgetsApi.list({ project_uuid: contract.project?.uuid, status: 'approved', per_page: 100 }),
  })
  const options = (budgets.data?.data ?? []).filter((b) => b.current_version?.status === 'approved')
  const [selected, setSelected] = useState('')
  const chosen = options.find((b) => b.uuid === selected)
  const run = useMutation({ mutationFn: () => contractsApi.items.fromBudget(contract.uuid, chosen!.uuid, chosen!.current_version!.number) })

  return (
    <Modal
      open
      onClose={run.isSuccess ? onDone : onClose}
      title="Gerar itens do orçamento aprovado"
      description="Copia os itens da versão aprovada com o preço de venda (BDI e descontos). O vínculo com o orçamento fica registrado."
      size="md"
      footer={
        run.isSuccess ? (
          <Button size="lg" onClick={onDone}>
            Concluir
          </Button>
        ) : (
          <>
            <Button variant="outline" size="lg" onClick={onClose}>
              Cancelar
            </Button>
            <Button size="lg" disabled={!chosen || run.isPending} onClick={() => run.mutate()}>
              Gerar itens
            </Button>
          </>
        )
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      {run.isSuccess ? (
        <div className="space-y-2 text-sm">
          <p>
            <Check className="text-success mr-1 inline size-4" /> {run.data.items} itens gerados. Total do contrato: <strong>{money(run.data.contract_total)}</strong>.
          </p>
          <p>Preço final do orçamento: {money(run.data.budget_final_price)}.</p>
          {Number(run.data.difference) !== 0 && (
            <Alert tone="warning">
              Diferença de {money(run.data.difference)} por arredondamento dos preços unitários (2 casas — D-P47). Ajuste um item se quiser zerar.
            </Alert>
          )}
        </div>
      ) : budgets.isPending ? (
        <LoadingState />
      ) : options.length === 0 ? (
        <EmptyState title="Nenhum orçamento aprovado nesta obra" description="Aprove um orçamento da obra em Orçamentos e volte aqui." />
      ) : (
        <FormField label="Orçamento (versão aprovada)" required>
          <SelectInput value={selected} onChange={(e) => setSelected(e.target.value)}>
            <option value="">Selecione…</option>
            {options.map((b) => (
              <option key={b.uuid} value={b.uuid}>
                {b.code} — {b.title} (v{b.current_version?.number}, {money(b.current_version?.final_price ?? '0')})
              </option>
            ))}
          </SelectInput>
        </FormField>
      )}
    </Modal>
  )
}

// ------------------------------------------------------------------ aditivos

function AddendaTab({ contract, canEdit, onChanged }: { contract: Contract; canEdit: boolean; onChanged: () => void }) {
  const { notify } = useToast()
  const list = useQuery({ queryKey: ['contract-addenda', contract.uuid], queryFn: () => contractsApi.addenda.list(contract.uuid) })
  const items = useQuery({ queryKey: ['contract-items', contract.uuid], queryFn: () => contractsApi.items.list(contract.uuid) })
  const [creating, setCreating] = useState(false)
  const [lineFor, setLineFor] = useState<Addendum | null>(null)
  const [approving, setApproving] = useState<Addendum | null>(null)
  const [canceling, setCanceling] = useState<Addendum | null>(null)
  const active = contract.status === 'active'

  const refresh = () => {
    list.refetch()
    onChanged()
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-sm">{active ? 'Aditivos de valor, prazo ou escopo. Só valem depois de aprovados.' : 'Só contrato ativo recebe aditivo.'}</p>
        <Button size="lg" disabled={!canEdit || !active} onClick={() => setCreating(true)}>
          <Plus className="size-4" /> Novo aditivo
        </Button>
      </div>
      {list.isPending ? (
        <LoadingState />
      ) : (list.data ?? []).length === 0 ? (
        <EmptyState title="Nenhum aditivo" description="Os aditivos e seus arquivos assinados aparecem aqui." />
      ) : (
        <div className="space-y-4">
          {list.data!.map((a) => {
            const draft = a.status === 'draft'
            return (
              <section key={a.number} className="rounded-lg border p-4" aria-label={`Aditivo ${a.number}`}>
                <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h3 className="font-semibold">
                      Aditivo {a.number} · {addendumKindLabel[a.kind]}
                    </h3>
                    <p className="text-sm whitespace-pre-line">{a.description}</p>
                    <p className="text-muted-foreground mt-1 text-xs">
                      {addendumStatusLabel[a.status]}
                      {a.signed_at && ` · assinado em ${formatDateOrDash(a.signed_at)}`}
                      {a.approved_by && ` · aprovado por ${a.approved_by.name}`}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="text-lg font-semibold tabular-nums">{Number(a.value_delta) >= 0 ? '+' : ''}{money(a.value_delta)}</p>
                    {a.new_end_date && <p className="text-muted-foreground text-xs">novo término {formatDateOrDash(a.new_end_date)}</p>}
                  </div>
                </div>
                {draft && a.over_25_percent && (
                  <Alert tone="warning" className="mb-2">
                    <AlertTriangle className="mr-1 inline size-4" /> Com este aditivo, os acréscimos passam de 25% do valor original (limite da Lei 14.133 para contratos públicos). Só um aviso.
                  </Alert>
                )}
                {a.lines.length > 0 && (
                  <table className="mb-2 w-full text-sm">
                    <caption className="sr-only">Itens do aditivo {a.number}</caption>
                    <thead className="text-muted-foreground text-left text-xs">
                      <tr>
                        <th scope="col" className="py-1">Item</th>
                        <th scope="col" className="py-1">Un.</th>
                        <th scope="col" className="py-1 text-right">Quantidade</th>
                        <th scope="col" className="py-1 text-right">Preço unit.</th>
                        {draft && canEdit && <th scope="col" className="w-10 py-1"><span className="sr-only">Ações</span></th>}
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {a.lines.map((l) => (
                        <tr key={l.id}>
                          <td className="py-1">
                            {l.line_type === 'new' && <span className="bg-success-soft text-success-strong mr-1 rounded px-1 text-[0.7rem]">novo</span>}
                            {l.description}
                          </td>
                          <td className="py-1">{l.unit_code}</td>
                          <td className="py-1 text-right tabular-nums">
                            {Number(l.quantity_delta) > 0 ? '+' : ''}
                            {qty(l.quantity_delta)}
                          </td>
                          <td className="py-1 text-right tabular-nums">{money(l.unit_price)}</td>
                          {draft && canEdit && (
                            <td className="py-1">
                              <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Remover linha"
                                onClick={() => contractsApi.addenda.removeLine(contract.uuid, a.number, l.id).then(refresh).catch((e) => notify('error', getErrorMessage(e)))}
                              >
                                <X />
                              </Button>
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
                {draft && canEdit && (
                  <div className="mb-3 flex flex-wrap gap-2">
                    {a.kind !== 'term' && (
                      <Button variant="outline" size="sm" onClick={() => setLineFor(a)}>
                        <Plus className="size-3.5" /> Item
                      </Button>
                    )}
                    <Button size="sm" disabled={!active} onClick={() => setApproving(a)}>
                      <Check className="size-3.5" /> Aprovar
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setCanceling(a)}>
                      Cancelar aditivo
                    </Button>
                  </div>
                )}
                {a.status !== 'canceled' && (
                  <div className="border-t pt-3">
                    <p className="text-muted-foreground mb-2 text-xs font-medium">Arquivos do aditivo</p>
                    <AttachmentsPanel type="contract_addendum" linkKey={String(a.id)} canEdit={canEdit} suggestedTitle={`Aditivo ${a.number} — ${contract.code}`} compact />
                  </div>
                )}
              </section>
            )
          })}
        </div>
      )}

      {creating && <AddendumDialog contract={contract} onClose={() => setCreating(false)} onDone={() => (setCreating(false), refresh())} />}
      {lineFor && <LineDialog contract={contract} addendum={lineFor} items={items.data ?? []} onClose={() => setLineFor(null)} onDone={() => (setLineFor(null), refresh())} />}
      {approving && (
        <ApproveDialog
          contract={contract}
          addendum={approving}
          onClose={() => setApproving(null)}
          onDone={(over) => {
            notify(over ? 'warning' : 'success', over ? 'Aditivo aprovado. Atenção: acréscimos acima de 25% do valor original.' : 'Aditivo aprovado.')
            setApproving(null)
            refresh()
          }}
        />
      )}
      <ConfirmDialog
        open={canceling !== null}
        title={`Cancelar o aditivo ${canceling?.number ?? ''}?`}
        description="O rascunho fica registrado como cancelado e não altera o contrato."
        confirmLabel="Cancelar aditivo"
        destructive
        onConfirm={() =>
          canceling &&
          contractsApi.addenda
            .cancel(contract.uuid, canceling.number)
            .then(() => (setCanceling(null), refresh()))
            .catch((e) => notify('error', getErrorMessage(e)))
        }
        onCancel={() => setCanceling(null)}
      />
    </div>
  )
}

function AddendumDialog({ contract, onClose, onDone }: { contract: Contract; onClose: () => void; onDone: () => void }) {
  const [kind, setKind] = useState<Addendum['kind']>('value')
  const [description, setDescription] = useState('')
  const [endDate, setEndDate] = useState('')
  const run = useMutation({ mutationFn: () => contractsApi.addenda.create(contract.uuid, { kind, description, new_end_date: endDate || null }), onSuccess: onDone })
  const needsDate = kind === 'term'
  return (
    <Modal
      open
      onClose={onClose}
      title="Novo aditivo"
      description="Valor e escopo: inclua os itens depois de criar. Prazo: informe a nova data de término."
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!description.trim() || run.isPending} onClick={() => run.mutate()}>
            Criar aditivo
          </Button>
        </>
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Tipo" required>
          <SelectInput value={kind} onChange={(e) => setKind(e.target.value as Addendum['kind'])}>
            {(Object.keys(addendumKindLabel) as Addendum['kind'][]).map((k) => (
              <option key={k} value={k}>
                {addendumKindLabel[k]}
              </option>
            ))}
          </SelectInput>
        </FormField>
        <FormField label="Novo término" required={needsDate} hint={`Término atual: ${formatDateOrDash(contract.current_end_date)}`}>
          <TextInput type="date" value={endDate} min={contract.starts_at} onChange={(e) => setEndDate(e.target.value)} />
        </FormField>
        <FormField label="Descrição / justificativa" required className="sm:col-span-2">
          <TextArea value={description} maxLength={5000} onChange={(e) => setDescription(e.target.value)} autoFocus />
        </FormField>
      </div>
    </Modal>
  )
}

function LineDialog({ contract, addendum, items, onClose, onDone }: { contract: Contract; addendum: Addendum; items: ContractItem[]; onClose: () => void; onDone: () => void }) {
  const [type, setType] = useState<'change' | 'new'>(items.length ? 'change' : 'new')
  const [itemId, setItemId] = useState('')
  const [delta, setDelta] = useState('')
  const [line, setLine] = useState({ code: '', description: '', unit_code: '', unit_price: '' })
  const item = items.find((i) => String(i.id) === itemId)
  const run = useMutation({
    mutationFn: () =>
      contractsApi.addenda.addLine(contract.uuid, addendum.number, type === 'change' ? { line_type: 'change', contract_item_id: Number(itemId), quantity_delta: delta } : { line_type: 'new', ...line, quantity_delta: delta }),
    onSuccess: onDone,
  })
  const ready = delta.trim() !== '' && Number(delta) !== 0 && (type === 'change' ? !!itemId : !!line.description.trim() && !!line.unit_code.trim() && line.unit_price !== '')
  return (
    <Modal
      open
      onClose={onClose}
      title={`Item do aditivo ${addendum.number}`}
      description="Altere a quantidade de um item existente (o preço não muda) ou inclua um item novo."
      size="lg"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!ready || run.isPending} onClick={() => run.mutate()}>
            Adicionar
          </Button>
        </>
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      <fieldset className="mb-3 flex gap-4 text-sm">
        <legend className="sr-only">Tipo da linha</legend>
        <label className="flex items-center gap-2">
          <input type="radio" className="accent-(--primary)" checked={type === 'change'} disabled={!items.length} onChange={() => setType('change')} /> Alterar quantidade de um item
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" className="accent-(--primary)" checked={type === 'new'} onChange={() => setType('new')} /> Incluir item novo
        </label>
      </fieldset>
      {type === 'change' ? (
        <div className="grid gap-3 sm:grid-cols-[1fr_10rem]">
          <FormField label="Item" required>
            <SelectInput value={itemId} onChange={(e) => setItemId(e.target.value)}>
              <option value="">Selecione…</option>
              {items.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.code ? `${i.code} — ` : ''}
                  {i.description} ({qty(i.quantity)} {i.unit_code})
                </option>
              ))}
            </SelectInput>
          </FormField>
          <FormField label="Acréscimo (−: supressão)" required hint={item ? `atual ${qty(item.quantity)} ${item.unit_code}` : undefined}>
            <TextInput inputMode="decimal" value={delta} onChange={(e) => setDelta(e.target.value.replace(',', '.'))} placeholder="ex.: 2,5 ou -1" />
          </FormField>
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Código">
            <TextInput value={line.code} onChange={(e) => setLine({ ...line, code: e.target.value })} />
          </FormField>
          <FormField label="Unidade" required>
            <TextInput value={line.unit_code} onChange={(e) => setLine({ ...line, unit_code: e.target.value })} />
          </FormField>
          <FormField label="Descrição" required className="sm:col-span-2">
            <TextArea value={line.description} onChange={(e) => setLine({ ...line, description: e.target.value })} />
          </FormField>
          <FormField label="Quantidade" required>
            <TextInput inputMode="decimal" value={delta} onChange={(e) => setDelta(e.target.value.replace(',', '.'))} />
          </FormField>
          <FormField label="Preço unitário (R$)" required>
            <TextInput inputMode="decimal" value={line.unit_price} onChange={(e) => setLine({ ...line, unit_price: e.target.value.replace(',', '.') })} />
          </FormField>
        </div>
      )}
    </Modal>
  )
}

function ApproveDialog({ contract, addendum, onClose, onDone }: { contract: Contract; addendum: Addendum; onClose: () => void; onDone: (over25: boolean) => void }) {
  const [signedAt, setSignedAt] = useState(today())
  const run = useMutation({ mutationFn: () => contractsApi.addenda.approve(contract.uuid, addendum.number, signedAt), onSuccess: (a) => onDone(!!a.over_25_percent) })
  return (
    <Modal
      open
      onClose={onClose}
      title={`Aprovar o aditivo ${addendum.number}?`}
      description="Depois de aprovado, o aditivo não muda: as quantidades, os itens novos e o prazo passam a valer no contrato."
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Voltar
          </Button>
          <Button size="lg" disabled={!signedAt || run.isPending} onClick={() => run.mutate()}>
            Aprovar
          </Button>
        </>
      }
    >
      {run.isError && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(run.error)}
        </Alert>
      )}
      <p className="mb-3 text-sm">
        Valor do aditivo: <strong>{money(addendum.value_delta)}</strong>
        {addendum.new_end_date && <> · novo término {formatDateOrDash(addendum.new_end_date)}</>}
      </p>
      <FormField label="Data de assinatura do aditivo" required>
        <TextInput type="date" value={signedAt} onChange={(e) => setSignedAt(e.target.value)} />
      </FormField>
    </Modal>
  )
}

function HistoryTab({ uuid }: { uuid: string }) {
  const q = useQuery({ queryKey: ['contract-history', uuid], queryFn: () => contractsApi.history(uuid) })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  if (q.data.length === 0) return <EmptyState title="Sem mudanças de status" description="Ativação, suspensão, conclusão e cancelamento aparecem aqui." />
  return (
    <ol className="space-y-2 text-sm">
      {q.data.map((h, i) => (
        <li key={i} className="rounded-lg border px-3 py-2">
          <span className="font-medium">
            {h.from ? contractStatusLabel[h.from as keyof typeof contractStatusLabel] : '—'} → {contractStatusLabel[h.to as keyof typeof contractStatusLabel]}
          </span>
          <span className="text-muted-foreground">
            {' '}
            · {new Date(h.at).toLocaleString('pt-BR')} · {h.by?.name ?? '—'}
          </span>
          {h.reason && <p className="text-muted-foreground mt-1">Motivo: {h.reason}</p>}
        </li>
      ))}
    </ol>
  )
}
