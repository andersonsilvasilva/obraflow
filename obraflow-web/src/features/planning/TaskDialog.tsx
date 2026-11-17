import { useMutation } from '@tanstack/react-query'
import { Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { FormField, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { depLabel, kindLabel, planningApi, type DepType, type PlanData, type PlanTask, type TaskKind } from './api'

interface Props {
  uuid: string
  tasks: PlanTask[]
  task?: PlanTask
  kind?: TaskKind
  canEdit: boolean
  onClose: () => void
  onSaved: (d: PlanData, msg: string) => void
}

/** Incluir/editar etapa, atividade ou marco; predecessoras; mover e excluir. O servidor recalcula tudo (D-P67). */
export function TaskDialog({ uuid, tasks, task, kind: initialKind, canEdit, onClose, onSaved }: Props) {
  const editing = !!task
  const kind: TaskKind = task?.kind ?? initialKind ?? 'task'
  const [form, setForm] = useState({
    name: task?.name ?? '',
    parent_id: task?.parent_id ? String(task.parent_id) : '',
    planned_start: task?.start ?? '',
    duration_days: task ? String(task.duration_days) : '1',
    unit_code: task?.unit_code ?? '',
    quantity: task?.quantity ? String(Number(task.quantity)) : '',
    percent_complete: task ? String(Number(task.percent)) : '0',
    actual_start: task?.actual_start ?? '',
    actual_end: task?.actual_end ?? '',
  })
  const [dep, setDep] = useState<{ task_id: string; type: DepType; lag: string }>({ task_id: '', type: 'FS', lag: '0' })
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const summaries = tasks.filter((t) => t.kind === 'summary' && t.id !== task?.id)
  const others = tasks.filter((t) => t.kind !== 'summary' && t.id !== task?.id && !task?.predecessors.some((p) => p.task_id === t.id))

  const save = useMutation({
    mutationFn: async () => {
      const common: Record<string, unknown> = { name: form.name.trim(), unit_code: form.unit_code || null, quantity: form.quantity || null }
      if (kind !== 'summary') common.planned_start = form.planned_start || undefined
      if (kind === 'task') common.duration_days = Number(form.duration_days) || 1
      if (!editing) {
        return planningApi.addTask(uuid, { ...common, kind, parent_id: form.parent_id ? Number(form.parent_id) : null })
      }
      if (kind !== 'summary') {
        Object.assign(common, { percent_complete: Number(form.percent_complete) || 0, actual_start: form.actual_start || null, actual_end: form.actual_end || null })
      }
      let data = await planningApi.updateTask(uuid, task!.id, common)
      const newParent = form.parent_id ? Number(form.parent_id) : null
      if (newParent !== task!.parent_id) {
        data = await planningApi.moveTask(uuid, task!.id, newParent, 9999)
      }
      return data
    },
    onSuccess: (d) => onSaved(d, editing ? 'Atividade atualizada.' : `${kindLabel[kind]} incluída.`),
  })
  const addDep = useMutation({
    mutationFn: () => planningApi.addDependency(uuid, { predecessor_id: Number(dep.task_id), successor_id: task!.id, type: dep.type, lag_days: Number(dep.lag) || 0 }),
    onSuccess: (d) => onSaved(d, 'Dependência criada.'),
  })
  const removeDep = useMutation({ mutationFn: (id: number) => planningApi.removeDependency(uuid, id), onSuccess: (d) => onSaved(d, 'Dependência removida.') })
  const remove = useMutation({ mutationFn: () => planningApi.removeTask(uuid, task!.id), onSuccess: (d) => onSaved(d, 'Excluída.') })
  const error = save.error ?? addDep.error ?? removeDep.error ?? remove.error
  const nameOf = (id: number) => {
    const t = tasks.find((x) => x.id === id)
    return t ? `${t.wbs} ${t.name}` : `#${id}`
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={editing ? `${kindLabel[kind]} ${task!.wbs}` : `Incluir ${kindLabel[kind].toLowerCase()}`}
      description={kind === 'summary' ? 'A etapa cobre o período das atividades dela; o avanço é calculado.' : kind === 'milestone' ? 'Marco: um evento sem duração (ex.: entrega).' : 'Duração em dias úteis da obra.'}
      size="lg"
      footer={
        <>
          {editing && canEdit && (
            <Button variant="outline" size="lg" className="mr-auto" disabled={remove.isPending} onClick={() => remove.mutate()}>
              <Trash2 className="size-4" /> Excluir{kind === 'summary' ? ' (com o que está dentro)' : ''}
            </Button>
          )}
          <Button variant="outline" size="lg" onClick={onClose}>
            {canEdit ? 'Cancelar' : 'Fechar'}
          </Button>
          {canEdit && (
            <Button size="lg" disabled={!form.name.trim() || save.isPending} onClick={() => save.mutate()}>
              Salvar
            </Button>
          )}
        </>
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {getErrorMessage(error)}
        </Alert>
      )}
      <fieldset disabled={!canEdit} className="grid gap-3 sm:grid-cols-2">
        <FormField label="Nome" required className="sm:col-span-2">
          <TextInput value={form.name} maxLength={250} onChange={(e) => set('name', e.target.value)} autoFocus />
        </FormField>
        <FormField label="Etapa (onde fica)" className="sm:col-span-2">
          <SelectInput value={form.parent_id} onChange={(e) => set('parent_id', e.target.value)}>
            <option value="">Nenhuma (primeiro nível)</option>
            {summaries.map((s) => (
              <option key={s.id} value={s.id}>
                {s.wbs} {s.name}
              </option>
            ))}
          </SelectInput>
        </FormField>
        {kind !== 'summary' && (
          <FormField label="Início" hint={task?.predecessors.length ? 'Com predecessora, o início vem da dependência.' : undefined}>
            <TextInput type="date" value={form.planned_start} onChange={(e) => set('planned_start', e.target.value)} />
          </FormField>
        )}
        {kind === 'task' && (
          <FormField label="Duração (dias úteis)" required hint={task ? `${task.calendar_days} dia(s) corridos · término ${new Date(`${task.end}T00:00:00`).toLocaleDateString('pt-BR')}` : undefined}>
            <TextInput type="number" min={1} value={form.duration_days} onChange={(e) => set('duration_days', e.target.value)} />
          </FormField>
        )}
        {kind === 'task' && (
          <>
            <FormField label="Quantidade">
              <TextInput inputMode="decimal" value={form.quantity} onChange={(e) => set('quantity', e.target.value.replace(',', '.'))} />
            </FormField>
            <FormField label="Unidade">
              <TextInput value={form.unit_code} maxLength={20} onChange={(e) => set('unit_code', e.target.value)} />
            </FormField>
          </>
        )}
        {editing && kind !== 'summary' && (
          <>
            <FormField label="Avanço (%)" hint="Na 6C, o RDO aprovado também soma ao avanço.">
              <TextInput type="number" min={0} max={100} step="0.01" value={form.percent_complete} onChange={(e) => set('percent_complete', e.target.value)} />
            </FormField>
            <div />
            <FormField label="Início real">
              <TextInput type="date" value={form.actual_start} onChange={(e) => set('actual_start', e.target.value)} />
            </FormField>
            <FormField label="Término real">
              <TextInput type="date" value={form.actual_end} onChange={(e) => set('actual_end', e.target.value)} />
            </FormField>
          </>
        )}
      </fieldset>

      {editing && kind !== 'summary' && (
        <section className="mt-5 border-t pt-4" aria-label="Predecessoras">
          <h3 className="mb-2 text-sm font-medium">Predecessoras</h3>
          {task!.predecessors.length === 0 ? (
            <p className="text-muted-foreground mb-2 text-sm">Nenhuma: a atividade começa na data informada.</p>
          ) : (
            <ul className="mb-3 divide-y rounded-lg border text-sm">
              {task!.predecessors.map((p) => (
                <li key={p.id} className="flex items-center gap-2 px-3 py-1.5">
                  <span className="flex-1">{nameOf(p.task_id)}</span>
                  <span className="text-muted-foreground text-xs">
                    {depLabel[p.type]}
                    {p.lag ? ` · ${p.lag > 0 ? '+' : ''}${p.lag} dia(s)` : ''}
                  </span>
                  {canEdit && (
                    <Button variant="ghost" size="icon-sm" aria-label="Remover dependência" onClick={() => removeDep.mutate(p.id)}>
                      <X />
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
          {canEdit && (
            <div className="grid gap-2 sm:grid-cols-[1fr_11rem_6rem_auto]">
              <SelectInput aria-label="Predecessora" value={dep.task_id} onChange={(e) => setDep({ ...dep, task_id: e.target.value })}>
                <option value="">Escolha a predecessora…</option>
                {others.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.wbs} {t.name}
                  </option>
                ))}
              </SelectInput>
              <SelectInput aria-label="Tipo" value={dep.type} onChange={(e) => setDep({ ...dep, type: e.target.value as DepType })}>
                {(Object.keys(depLabel) as DepType[]).map((t) => (
                  <option key={t} value={t}>
                    {depLabel[t]}
                  </option>
                ))}
              </SelectInput>
              <TextInput aria-label="Atraso em dias úteis" type="number" value={dep.lag} onChange={(e) => setDep({ ...dep, lag: e.target.value })} title="Atraso (+) ou adiantamento (−) em dias úteis" />
              <Button variant="outline" disabled={!dep.task_id || addDep.isPending} onClick={() => addDep.mutate()}>
                Ligar
              </Button>
            </div>
          )}
        </section>
      )}
    </Modal>
  )
}
