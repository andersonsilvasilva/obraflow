import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Pencil, Plus, RefreshCw, Trash2, UserMinus } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '@/app/providers/ToastProvider'
import { Panel } from '@/components/data-display/DataTable'
import { ProgressIndicator, StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { ConfirmDialog, Modal } from '@/components/feedback/Modal'
import { EmptyState, ErrorState, LoadingState } from '@/components/feedback/states'
import { FormField, SelectInput, TextArea } from '@/components/forms/fields'
import { Tabs } from '@/components/layout/Tabs'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { EntityFormModal, type FieldDef } from '@/features/registry/components/EntityFormModal'
import { useEmployeeOptions } from '@/features/registry/lookups'
import { getErrorMessage } from '@/lib/api-error'
import { http } from '@/lib/http'
import { formatCurrency, formatDateOrDash } from '@/utils/format'
import { projectFields, projectToForm, useProjectLookups } from './form'
import {
  atLeast,
  levelOptions,
  projectsApi,
  statusName,
  statusTone,
  transitions,
  type AccessLevel,
  type Address,
  type Member,
  type Phase,
  type Project,
  type ProjectStatus,
} from './api'

const UFS = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ').map((u) => ({ value: u, label: u }))
const kindOptions = [
  { value: 'site', label: 'Canteiro' },
  { value: 'main', label: 'Principal' },
  { value: 'billing', label: 'Cobrança' },
  { value: 'delivery', label: 'Entrega' },
]

const addressFields: FieldDef[] = [
  { name: 'kind', label: 'Tipo', type: 'select', options: kindOptions, placeholder: 'Canteiro (padrão)' },
  { name: 'postal_code', label: 'CEP', placeholder: '00000-000' },
  { name: 'street', label: 'Logradouro', required: true, full: true },
  { name: 'number', label: 'Número' },
  { name: 'complement', label: 'Complemento' },
  { name: 'district', label: 'Bairro' },
  { name: 'city', label: 'Cidade', required: true },
  { name: 'state', label: 'UF', type: 'select', options: UFS, required: true },
]

type Dialog =
  | { kind: 'edit' }
  | { kind: 'status' }
  | { kind: 'address'; row?: Address }
  | { kind: 'phase'; row?: Phase }
  | { kind: 'member'; row?: Member }
  | { kind: 'grant' }
  | { kind: 'confirm'; title: string; run: () => Promise<unknown> }

/** Detalhe da obra. Ações conforme o nível de acesso do usuário NA OBRA (D-P24); a API confere de novo. */
export function ProjectDetailPage() {
  const { uuid = '' } = useParams()
  const { notify } = useToast()
  const client = useQueryClient()
  const [tab, setTab] = useState('dados')
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const lookups = useProjectLookups()

  const query = useQuery({ queryKey: ['project', uuid], queryFn: () => projectsApi.get(uuid) })
  const project = query.data
  const canEdit = atLeast(project?.access_level, 'edit')
  const canManage = atLeast(project?.access_level, 'manage')

  const refresh = () => {
    client.invalidateQueries({ queryKey: ['project', uuid] })
    client.invalidateQueries({ queryKey: ['projects'] })
    client.invalidateQueries({ queryKey: ['project-history', uuid] })
    client.invalidateQueries({ queryKey: ['project-access', uuid] })
  }
  const done = (msg: string) => {
    notify('success', msg)
    setDialog(null)
    refresh()
  }

  if (query.isPending) return <LoadingState />
  if (query.isError || !project) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const tabs = [
    { id: 'dados', label: 'Dados gerais' },
    { id: 'endereco', label: 'Endereço' },
    { id: 'fases', label: 'Fases' },
    { id: 'equipe', label: 'Equipe' },
    { id: 'historico', label: 'Histórico de status' },
    ...(canManage ? [{ id: 'acesso', label: 'Acesso' }] : []),
  ]

  return (
    <div>
      <Link to="/obras" className="text-muted-foreground hover:text-primary mb-2 inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="size-4" /> Obras
      </Link>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3 border-b pb-4">
        <div>
          <p className="text-muted-foreground text-sm">{project.code}</p>
          <h1 className="text-2xl font-semibold tracking-tight">{project.name}</h1>
          <div className="mt-2 flex items-center gap-3">
            <StatusBadge tone={statusTone[project.status]}>{statusName(project.status)}</StatusBadge>
            <ProgressIndicator value={project.progress} />
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="lg" disabled={!canEdit || transitions[project.status].length === 0} onClick={() => setDialog({ kind: 'status' })}>
            <RefreshCw className="size-4" /> Mudar status
          </Button>
          <Button size="lg" disabled={!canEdit} onClick={() => setDialog({ kind: 'edit' })}>
            <Pencil className="size-4" /> Editar
          </Button>
        </div>
      </div>

      <Panel className="p-4">
        <Tabs tabs={tabs} active={tab} onChange={setTab} label="Seções da obra">
          {tab === 'dados' && <GeneralTab project={project} />}
          {tab === 'endereco' && (
            <ListSection
              title="Endereços"
              canAdd={canEdit}
              onAdd={() => setDialog({ kind: 'address' })}
              empty="Nenhum endereço cadastrado."
              items={project.addresses ?? []}
              render={(a) => (
                <>
                  <span className="flex-1">
                    <span className="font-medium">{kindOptions.find((k) => k.value === a.kind)?.label}</span> — {a.street}
                    {a.number ? `, ${a.number}` : ''} {a.district ? `· ${a.district}` : ''} · {a.city}/{a.state}
                  </span>
                  <RowActions
                    disabled={!canEdit}
                    onEdit={() => setDialog({ kind: 'address', row: a })}
                    onDelete={() => setDialog({ kind: 'confirm', title: 'Excluir endereço?', run: () => projectsApi.addresses.remove(uuid, a.id) })}
                  />
                </>
              )}
            />
          )}
          {tab === 'fases' && (
            <ListSection
              title="Fases da obra"
              canAdd={canEdit}
              onAdd={() => setDialog({ kind: 'phase' })}
              empty="Nenhuma fase cadastrada."
              items={orderedPhases(project.phases ?? [])}
              render={({ phase, depth }) => (
                <>
                  <span className="flex-1" style={{ paddingLeft: `${depth * 1.25}rem` }}>
                    <span className={depth === 0 ? 'font-medium' : ''}>{phase.name}</span>
                    <span className="text-muted-foreground ml-2 text-xs">
                      {phase.planned_start || phase.planned_end ? `${formatDateOrDash(phase.planned_start)} → ${formatDateOrDash(phase.planned_end)}` : ''}
                    </span>
                  </span>
                  <RowActions
                    disabled={!canEdit}
                    onEdit={() => setDialog({ kind: 'phase', row: phase })}
                    onDelete={() => setDialog({ kind: 'confirm', title: `Excluir a fase "${phase.name}"?`, run: () => projectsApi.phases.remove(uuid, phase.id) })}
                  />
                </>
              )}
            />
          )}
          {tab === 'equipe' && (
            <ListSection
              title="Equipe da obra"
              canAdd={canEdit}
              onAdd={() => setDialog({ kind: 'member' })}
              empty="Nenhum funcionário na equipe."
              items={project.members ?? []}
              render={(m) => (
                <>
                  <span className="flex-1">
                    <span className="font-medium">{m.employee?.name ?? '(funcionário excluído)'}</span>
                    {m.role && <span className="text-muted-foreground"> · {m.role}</span>}
                    <span className="text-muted-foreground ml-2 text-xs">
                      {formatDateOrDash(m.started_on)} → {m.ended_on ? formatDateOrDash(m.ended_on) : 'atual'}
                    </span>
                  </span>
                  {!m.ended_on && <StatusBadge tone="success">Ativo</StatusBadge>}
                  <RowActions
                    disabled={!canEdit}
                    onEdit={() => setDialog({ kind: 'member', row: m })}
                    onDelete={() => setDialog({ kind: 'confirm', title: 'Remover da equipe?', run: () => projectsApi.members.remove(uuid, m.id) })}
                  />
                </>
              )}
            />
          )}
          {tab === 'historico' && <HistoryTab uuid={uuid} />}
          {tab === 'acesso' && canManage && <AccessTab uuid={uuid} onGrant={() => setDialog({ kind: 'grant' })} onRevoke={(u, name) => setDialog({ kind: 'confirm', title: `Remover o acesso de ${name}?`, run: () => projectsApi.access.revoke(uuid, u) })} />}
        </Tabs>
      </Panel>

      {dialog?.kind === 'edit' && (
        <EntityFormModal
          title="Editar obra"
          size="xl"
          mode="edit"
          fields={projectFields}
          lookups={lookups}
          initial={projectToForm(project)}
          onClose={() => setDialog(null)}
          onSubmit={(b) => projectsApi.update(uuid, b)}
          onSaved={() => done('Obra atualizada')}
        />
      )}
      {dialog?.kind === 'status' && <StatusDialog project={project} onClose={() => setDialog(null)} onDone={() => done('Status alterado')} />}
      {dialog?.kind === 'address' && (
        <EntityFormModal
          title={dialog.row ? 'Editar endereço' : 'Adicionar endereço'}
          mode={dialog.row ? 'edit' : 'create'}
          fields={addressFields}
          lookups={{}}
          initial={dialog.row ? stringify(dialog.row) : {}}
          onClose={() => setDialog(null)}
          onSubmit={(b) => (dialog.row ? projectsApi.addresses.update(uuid, dialog.row.id, b) : projectsApi.addresses.create(uuid, b))}
          onSaved={() => done('Endereço salvo')}
        />
      )}
      {dialog?.kind === 'phase' && <PhaseDialog project={project} row={dialog.row} onClose={() => setDialog(null)} onDone={() => done('Fase salva')} />}
      {dialog?.kind === 'member' && <MemberDialog uuid={uuid} row={dialog.row} onClose={() => setDialog(null)} onDone={() => done('Equipe atualizada')} />}
      {dialog?.kind === 'grant' && <GrantDialog uuid={uuid} onClose={() => setDialog(null)} onDone={() => done('Acesso concedido')} />}
      <ConfirmDialog
        open={dialog?.kind === 'confirm'}
        title={dialog?.kind === 'confirm' ? dialog.title : ''}
        description="Esta ação não pode ser desfeita pela tela."
        confirmLabel="Confirmar"
        destructive
        onCancel={() => setDialog(null)}
        onConfirm={async () => {
          if (dialog?.kind !== 'confirm') return
          try {
            await dialog.run()
            done('Concluído')
          } catch (e) {
            notify('error', 'Não foi possível concluir', getErrorMessage(e))
            setDialog(null)
          }
        }}
      />
    </div>
  )
}

function GeneralTab({ project }: { project: Project }) {
  const rows: [string, ReactNode][] = [
    ['Empresa', project.company?.legal_name ?? '—'],
    ['Cliente', project.customer?.name ?? '—'],
    ['Responsável técnico', project.technical_manager?.name ?? '—'],
    ['Início', formatDateOrDash(project.start_date)],
    ['Previsão de término', formatDateOrDash(project.expected_end_date)],
    ['Término real', formatDateOrDash(project.actual_end_date)],
    ['Valor do contrato', project.contract_value ? formatCurrency(Number(project.contract_value)) : '—'],
    ['Seu acesso', levelOptions.find((l) => l.value === project.access_level)?.label ?? '—'],
  ]
  return (
    <div>
      <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map(([k, v]) => (
          <div key={k}>
            <dt className="text-muted-foreground text-xs">{k}</dt>
            <dd className="text-sm font-medium">{v}</dd>
          </div>
        ))}
      </dl>
      {project.description && <p className="mt-4 text-sm whitespace-pre-line">{project.description}</p>}
    </div>
  )
}

function ListSection<T>({
  title,
  items,
  render,
  empty,
  canAdd,
  onAdd,
}: {
  title: string
  items: T[]
  render: (item: T) => ReactNode
  empty: string
  canAdd: boolean
  onAdd: () => void
}) {
  return (
    <section aria-label={title}>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">{title}</h2>
        <Button onClick={onAdd} disabled={!canAdd} title={canAdd ? undefined : 'Seu nível nesta obra não permite alterar'}>
          <Plus className="size-4" /> Adicionar
        </Button>
      </div>
      {items.length === 0 ? (
        <EmptyState title={empty} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {items.map((item, i) => (
            <li key={i} className="flex items-center gap-3 px-3 py-2 text-sm">
              {render(item)}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function RowActions({ disabled, onEdit, onDelete }: { disabled: boolean; onEdit: () => void; onDelete: () => void }) {
  return (
    <span className="flex gap-1">
      <Button variant="ghost" size="icon" aria-label="Editar" disabled={disabled} onClick={onEdit}>
        <Pencil className="size-4" />
      </Button>
      <Button variant="ghost" size="icon" aria-label="Excluir" disabled={disabled} onClick={onDelete}>
        <Trash2 className="size-4" />
      </Button>
    </span>
  )
}

/** Árvore de fases em ordem de exibição, com a profundidade de cada uma. */
function orderedPhases(phases: Phase[]): { phase: Phase; depth: number }[] {
  const out: { phase: Phase; depth: number }[] = []
  const walk = (parent: number | null, depth: number) =>
    phases
      .filter((p) => p.parent_id === parent)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
      .forEach((p) => {
        out.push({ phase: p, depth })
        walk(p.id, depth + 1)
      })
  walk(null, 0)
  return out
}

function StatusDialog({ project, onClose, onDone }: { project: Project; onClose: () => void; onDone: () => void }) {
  const next = transitions[project.status]
  const [to, setTo] = useState<ProjectStatus>(next[0])
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const change = useMutation({
    mutationFn: () => projectsApi.changeStatus(project.uuid, to, reason.trim() || null),
    onSuccess: onDone,
    onError: (e) => setError(getErrorMessage(e)),
  })
  return (
    <Modal
      open
      onClose={onClose}
      title="Mudar status da obra"
      description={`Status atual: ${statusName(project.status)}`}
      size="md"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" onClick={() => change.mutate()} disabled={change.isPending}>
            Salvar
          </Button>
        </>
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      <div className="flex flex-col gap-4">
        <FormField label="Novo status" required>
          <SelectInput value={to} onChange={(e) => setTo(e.target.value as ProjectStatus)}>
            {next.map((s) => (
              <option key={s} value={s}>
                {statusName(s)}
              </option>
            ))}
          </SelectInput>
        </FormField>
        <FormField label="Motivo" hint="Fica registrado no histórico e na auditoria.">
          <TextArea value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
        </FormField>
        {(to === 'completed' || to === 'canceled') && <Alert tone="warning">Concluída e cancelada são status finais: não é possível voltar.</Alert>}
      </div>
    </Modal>
  )
}

function PhaseDialog({ project, row, onClose, onDone }: { project: Project; row?: Phase; onClose: () => void; onDone: () => void }) {
  const parents = useMemo(
    () =>
      orderedPhases(project.phases ?? [])
        .filter(({ phase, depth }) => depth < 2 && phase.id !== row?.id)
        .map(({ phase, depth }) => ({ value: String(phase.id), label: `${'— '.repeat(depth)}${phase.name}` })),
    [project.phases, row],
  )
  const fields: FieldDef[] = [
    { name: 'name', label: 'Nome', required: true, full: true },
    { name: 'parent_id', label: 'Fase superior', type: 'select', options: parents, placeholder: 'Nenhuma (fase principal)', help: 'Até 3 níveis.' },
    { name: 'sort_order', label: 'Ordem', type: 'number', step: '1' },
    { name: 'planned_start', label: 'Início previsto', type: 'date' },
    { name: 'planned_end', label: 'Término previsto', type: 'date' },
  ]
  return (
    <EntityFormModal
      title={row ? 'Editar fase' : 'Adicionar fase'}
      mode={row ? 'edit' : 'create'}
      fields={fields}
      lookups={{}}
      initial={row ? stringify(row) : {}}
      onClose={onClose}
      onSubmit={(b) => (row ? projectsApi.phases.update(project.uuid, row.id, b) : projectsApi.phases.create(project.uuid, b))}
      onSaved={onDone}
    />
  )
}

function MemberDialog({ uuid, row, onClose, onDone }: { uuid: string; row?: Member; onClose: () => void; onDone: () => void }) {
  const employees = useEmployeeOptions()
  const fields: FieldDef[] = [
    { name: 'employee_uuid', label: 'Funcionário', type: 'select', options: employees, required: true, createOnly: true, full: true, help: 'Funcionário da mesma empresa da obra.' },
    { name: 'role', label: 'Função na obra', placeholder: 'Ex.: Mestre de obras' },
    { name: 'started_on', label: 'Início', type: 'date', required: true },
    { name: 'ended_on', label: 'Saída', type: 'date', help: 'Preencha para encerrar a participação (fica no histórico).' },
  ]
  return (
    <EntityFormModal
      title={row ? 'Editar participação' : 'Adicionar à equipe'}
      mode={row ? 'edit' : 'create'}
      fields={fields}
      lookups={{}}
      initial={row ? { employee_uuid: row.employee?.uuid ?? '', role: row.role ?? '', started_on: row.started_on ?? '', ended_on: row.ended_on ?? '' } : {}}
      onClose={onClose}
      onSubmit={(b) => (row ? projectsApi.members.update(uuid, row.id, b) : projectsApi.members.create(uuid, b))}
      onSaved={onDone}
    />
  )
}

function HistoryTab({ uuid }: { uuid: string }) {
  const q = useQuery({ queryKey: ['project-history', uuid], queryFn: () => projectsApi.history(uuid) })
  if (q.isPending) return <LoadingState />
  if (q.isError) return <ErrorState error={q.error} />
  if (q.data.length === 0) return <EmptyState title="Nenhuma mudança de status ainda." />
  return (
    <ol className="flex flex-col gap-3">
      {q.data.map((h, i) => (
        <li key={i} className="border-primary border-l-2 pl-3 text-sm">
          <p>
            {h.from_status ? statusName(h.from_status) : '—'} → <span className="font-medium">{statusName(h.to_status)}</span>
          </p>
          <p className="text-muted-foreground text-xs">
            {new Date(h.changed_at).toLocaleString('pt-BR')} · {h.changed_by?.name ?? '—'}
          </p>
          {h.reason && <p className="mt-1">{h.reason}</p>}
        </li>
      ))}
    </ol>
  )
}

function AccessTab({ uuid, onGrant, onRevoke }: { uuid: string; onGrant: () => void; onRevoke: (userUuid: string, name: string) => void }) {
  const q = useQuery({ queryKey: ['project-access', uuid], queryFn: () => projectsApi.access.list(uuid) })
  return (
    <section aria-label="Acesso à obra">
      <Alert tone="info" className="mb-3">
        Quem administra obras na organização vê todas. Os demais só veem as obras liberadas aqui; o nível limita o que podem fazer.
      </Alert>
      <div className="mb-3 flex justify-end">
        <Button onClick={onGrant}>
          <Plus className="size-4" /> Conceder acesso
        </Button>
      </div>
      {q.isPending ? (
        <LoadingState />
      ) : q.isError ? (
        <ErrorState error={q.error} />
      ) : q.data.length === 0 ? (
        <EmptyState title="Nenhum acesso concedido nesta obra." />
      ) : (
        <ul className="divide-y rounded-lg border">
          {q.data.map((a) => (
            <li key={a.user?.uuid} className="flex items-center gap-3 px-3 py-2 text-sm">
              <span className="flex-1">
                <span className="font-medium">{a.user?.name}</span> <span className="text-muted-foreground">{a.user?.email}</span>
              </span>
              <StatusBadge tone="primary">{levelOptions.find((l) => l.value === a.access_level)?.label}</StatusBadge>
              <Button variant="ghost" size="icon" aria-label={`Remover acesso de ${a.user?.name}`} onClick={() => a.user && onRevoke(a.user.uuid, a.user.name)}>
                <UserMinus className="size-4" />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function GrantDialog({ uuid, onClose, onDone }: { uuid: string; onClose: () => void; onDone: () => void }) {
  const { can } = useAuth()
  const members = useQuery({
    queryKey: ['members', 'options'],
    queryFn: () => http<{ data: { user: { uuid: string; name: string; email: string }; status: string }[] }>('/members'),
    enabled: can('members.view'),
  })
  const users = (members.data?.data ?? []).filter((m) => m.status === 'active').map((m) => ({ value: m.user.uuid, label: `${m.user.name} (${m.user.email})` }))
  const fields: FieldDef[] = [
    { name: 'user_uuid', label: 'Usuário', type: 'select', options: users, required: true, full: true, help: 'Membro ativo da organização.' },
    { name: 'access_level', label: 'Nível', type: 'radio', options: levelOptions, required: true, full: true },
  ]
  return (
    <EntityFormModal
      title="Conceder acesso à obra"
      mode="create"
      size="md"
      fields={fields}
      lookups={{}}
      initial={{}}
      onClose={onClose}
      onSubmit={(b) => projectsApi.access.grant(uuid, String(b.user_uuid), b.access_level as AccessLevel)}
      onSaved={onDone}
    />
  )
}

function stringify(row: object): Record<string, string> {
  return Object.fromEntries(Object.entries(row).map(([k, v]) => [k, v === null || v === undefined ? '' : String(v)]))
}
