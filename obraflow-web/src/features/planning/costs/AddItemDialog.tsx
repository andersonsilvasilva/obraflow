import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Loader2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { FormField, SearchInput, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { budgetsApi, regimeLabel, type RefResult } from '@/features/budgets/api'
import { catalogApi, ownCompositionsApi, type OwnComposition, type ResourceKind } from '@/features/catalog/api'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { formatCurrency } from '@/utils/format'
import { planningApi, type PlanData, type PlanTask } from '../api'

export type AddMode = 'resources' | 'public' | 'own'
type Regime = 'with_relief' | 'without_relief' | 'not_applicable' | null
type Tab = ResourceKind | 'other'

const TABS: { id: Tab; label: string }[] = [
  { id: 'material', label: 'Materiais' },
  { id: 'labor', label: 'Cargo/Função' },
  { id: 'equipment', label: 'Equipamentos' },
  { id: 'service', label: 'Serviços' },
  { id: 'other', label: 'Outros' },
]

const regimeKey: Record<string, 'with_relief' | 'without_relief' | 'not_applicable'> = { with_relief: 'with_relief', without_relief: 'without_relief' }

function useDebounced(term: string) {
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(term.trim()), 300)
    return () => clearTimeout(t)
  }, [term])
  return q
}

interface Props {
  uuid: string
  task: PlanTask
  mode: AddMode
  regime: Regime
  onClose: () => void
  onDone: (d: PlanData) => void
}

/**
 * Lançar recursos numa atividade (6B): do catálogo da empresa, por tipo e com seleção de vários (D-P90); uma
 * composição ou insumo SINAPI da base com o regime da obra (D-P89); ou uma composição própria (D-P87/D-P88).
 * O preço é copiado no momento (D-08). O consumo é por unidade da atividade (D-P91).
 */
export function AddItemDialog({ uuid, task, mode, regime, onClose, onDone }: Props) {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('material')
  const [term, setTerm] = useState('')
  const q = useDebounced(term)
  const [picked, setPicked] = useState<string[]>([])
  const [qpu, setQpu] = useState('1')
  const [error, setError] = useState<string | null>(null)
  const [manual, setManual] = useState({ description: '', unit_code: 'UN', unit_cost: '' })
  const [refType, setRefType] = useState<'compositions' | 'resources'>('compositions')
  const [dataset, setDataset] = useState<number | null>(null)

  // catálogo
  const catalog = useQuery({
    queryKey: ['catalog-search', tab, q],
    queryFn: () => catalogApi.list({ kind: tab, q, status: 'active', per_page: 25 }),
    enabled: mode === 'resources' && tab !== 'other' && can('resources.view'),
  })
  // SINAPI
  const datasets = useQuery({ queryKey: ['reference-datasets'], queryFn: () => budgetsApi.references.datasets(), staleTime: 300_000, enabled: mode === 'public' })
  const compatible = (datasets.data ?? []).filter((d) => regime !== null && d.charges_regime === regime && d.status === 'active')
  const ds = dataset ?? compatible[0]?.id ?? null
  const refs = useQuery({
    queryKey: ['reference-search', ds, refType, q],
    queryFn: () => budgetsApi.references.search(ds!, refType, q).then((r) => r.data),
    enabled: mode === 'public' && ds !== null && q.length >= 2,
  })
  // composições próprias
  const own = useQuery({ queryKey: ['own-compositions', 'search', q], queryFn: () => ownCompositionsApi.list({ q, status: 'active', per_page: 25 }), enabled: mode === 'own' })

  const add = useMutation({
    mutationFn: () => {
      const base = { quantity_per_unit: qpu.replace(',', '.') || '1' }
      if (mode === 'own') return planningApi.addItems(uuid, task.id, { ...base, source: 'own_composition', own_composition_uuid: picked[0] })
      if (mode === 'public') return planningApi.addItems(uuid, task.id, { ...base, source: refType === 'compositions' ? 'sinapi_composition' : 'sinapi_resource', reference_dataset_id: ds, code: picked[0] })
      if (tab === 'other') return planningApi.addItems(uuid, task.id, { ...base, source: 'manual', kind: 'other', ...manual, unit_cost: manual.unit_cost.replace(',', '.') })
      return planningApi.addItems(uuid, task.id, { ...base, source: 'catalog', resource_uuids: picked })
    },
    onSuccess: (d) => onDone(d),
    onError: (e) => setError(getErrorMessage(e)),
  })

  const single = mode !== 'resources'
  const toggle = (id: string) => setPicked((cur) => (single ? [id] : cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]))
  const ready = !add.isPending && (mode === 'resources' && tab === 'other' ? manual.description.trim() !== '' && manual.unit_cost !== '' : picked.length > 0)
  const titles: Record<AddMode, string> = { resources: 'Recursos da atividade', public: 'Composição pública (SINAPI)', own: 'Composição própria' }
  const unit = task.unit_code ?? 'unidade'

  const row = (id: string, code: string | null, desc: string, unitCode: string, price: string | null | undefined, extra?: string) => (
    <li key={id}>
      <button
        type="button"
        role="option"
        aria-selected={picked.includes(id)}
        onClick={() => toggle(id)}
        className={cn('hover:bg-primary-soft/50 flex w-full items-start gap-3 px-3 py-2 text-left text-sm', picked.includes(id) && 'bg-primary-soft')}
      >
        <span className={cn('mt-0.5 flex size-4 shrink-0 items-center justify-center rounded border', picked.includes(id) && 'border-primary bg-primary text-primary-foreground')}>
          {picked.includes(id) && <Check className="size-3" />}
        </span>
        <span className="w-16 shrink-0 font-mono text-xs">{code ?? ''}</span>
        <span className="flex-1">
          {desc}
          {extra && <span className="text-muted-foreground block text-xs">{extra}</span>}
        </span>
        <span className="text-muted-foreground w-10 shrink-0">{unitCode}</span>
        <span className="w-24 shrink-0 text-right tabular-nums">{price ? formatCurrency(Number(price)) : 'sem preço'}</span>
      </button>
    </li>
  )

  const list = (content: React.ReactNode, empty: string, loading: boolean, hint?: string) => (
    <div className="mt-3 max-h-72 overflow-y-auto rounded-lg border">
      {hint ? <p className="text-muted-foreground p-4 text-sm">{hint}</p> : loading ? <p className="text-muted-foreground p-4 text-sm">Buscando…</p> : content ?? <p className="text-muted-foreground p-4 text-sm">{empty}</p>}
    </div>
  )

  const ownRegime = regimeKey[regime ?? ''] ?? 'not_applicable'

  return (
    <Modal
      open
      onClose={onClose}
      title={titles[mode]}
      description={`${task.wbs} ${task.name}${task.quantity ? ` · ${Number(task.quantity).toLocaleString('pt-BR')} ${unit}` : ''}. O preço é copiado agora; mudanças no cadastro não alteram o planejamento.`}
      size="xl"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="lg" disabled={!ready} onClick={() => add.mutate()}>
            {add.isPending && <Loader2 className="size-4 animate-spin" />} Adicionar{!single && picked.length > 1 ? ` (${picked.length})` : ''}
          </Button>
        </>
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}

      {mode === 'resources' && (
        <div className="mb-3 flex flex-wrap gap-1" role="tablist" aria-label="Tipo de recurso">
          {TABS.map((t) => (
            <Button key={t.id} role="tab" aria-selected={tab === t.id} variant={tab === t.id ? 'default' : 'outline'} size="sm" onClick={() => (setTab(t.id), setPicked([]))}>
              {t.label}
            </Button>
          ))}
        </div>
      )}

      {mode === 'public' && (
        <>
          {regime === null || regime === 'not_applicable' ? (
            <Alert tone="warning" className="mb-3">
              A obra está "sem encargos" (ou sem regime definido): composições SINAPI não se aplicam. Defina o encargo social na obra ou use o catálogo e as composições próprias.
            </Alert>
          ) : compatible.length === 0 && !datasets.isPending ? (
            <Alert tone="warning" className="mb-3">
              Nenhuma base SINAPI ativa com o regime da obra ({regimeLabel[regime]}).
            </Alert>
          ) : null}
          <div className="mb-3 grid gap-3 sm:grid-cols-2">
            <FormField label="Base SINAPI (mesmo regime da obra)">
              <SelectInput value={ds ?? ''} onChange={(e) => (setDataset(Number(e.target.value)), setPicked([]))}>
                {compatible.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.source?.code} {d.state} {d.competency} — {regimeLabel[d.charges_regime]}
                  </option>
                ))}
              </SelectInput>
            </FormField>
            <FormField label="Buscar em">
              <SelectInput value={refType} onChange={(e) => (setRefType(e.target.value as 'compositions' | 'resources'), setPicked([]))}>
                <option value="compositions">Composições</option>
                <option value="resources">Insumos</option>
              </SelectInput>
            </FormField>
          </div>
        </>
      )}

      {!(mode === 'resources' && tab === 'other') && (
        <SearchInput
          placeholder={mode === 'public' ? 'Código ou palavras da descrição (mín. 2 caracteres)' : 'Buscar por nome ou código'}
          aria-label="Buscar"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          autoFocus
        />
      )}

      {mode === 'resources' &&
        tab !== 'other' &&
        list(
          catalog.data?.data.length ? <ul className="divide-y" role="listbox" aria-multiselectable="true" aria-label="Insumos do catálogo">{catalog.data.data.map((r) => row(r.uuid, r.code, r.name, r.unit?.code ?? '', r.price))}</ul> : null,
          'Nada encontrado no catálogo deste tipo.',
          catalog.isPending,
          can('resources.view') ? undefined : 'Sem acesso ao catálogo de insumos.',
        )}

      {mode === 'resources' && tab === 'other' && (
        <div className="grid gap-3 sm:grid-cols-3">
          <FormField label="Descrição" required className="sm:col-span-3">
            <TextInput maxLength={500} value={manual.description} onChange={(e) => setManual({ ...manual, description: e.target.value })} autoFocus />
          </FormField>
          <FormField label="Unidade" required>
            <TextInput maxLength={20} value={manual.unit_code} onChange={(e) => setManual({ ...manual, unit_code: e.target.value })} />
          </FormField>
          <FormField label="Custo unitário (R$)" required>
            <TextInput inputMode="decimal" value={manual.unit_cost} onChange={(e) => setManual({ ...manual, unit_cost: e.target.value })} />
          </FormField>
        </div>
      )}

      {mode === 'public' &&
        list(
          refs.data?.length ? <ul className="divide-y" role="listbox" aria-label="Resultados">{refs.data.map((r: RefResult) => row(r.code, r.code, r.description, r.unit, r.total_cost ?? r.price))}</ul> : null,
          'Nada encontrado.',
          refs.isFetching,
          q.length < 2 ? 'Digite para buscar.' : undefined,
        )}

      {mode === 'own' &&
        list(
          own.data?.data.length ? (
            <ul className="divide-y" role="listbox" aria-label="Composições próprias">
              {own.data.data.map((c: OwnComposition) => row(c.uuid, c.code, c.name, c.unit?.code ?? '', c.totals[ownRegime], `Valor no regime da obra (${regimeLabel[ownRegime] ?? 'sem encargos'})`))}
            </ul>
          ) : null,
          'Nenhuma composição própria ativa.',
          own.isPending,
        )}
      {mode === 'own' && (
        <p className="text-muted-foreground mt-2 text-xs">
          Cadastre e edite em{' '}
          <Link className="text-primary underline" to="/cadastros/composicoes-proprias" target="_blank">
            Cadastros → Recursos → Composições próprias
          </Link>
          .
        </p>
      )}

      <div className="mt-4 max-w-xs">
        <FormField label={`Consumo por ${unit}`} hint={task.quantity ? `Quantidade total = ${Number(task.quantity).toLocaleString('pt-BR')} × consumo.` : 'A atividade não tem quantidade: o consumo é o total.'}>
          <TextInput inputMode="decimal" value={qpu} onChange={(e) => setQpu(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  )
}
