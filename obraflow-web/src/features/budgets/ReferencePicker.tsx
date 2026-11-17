import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Loader2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { FormField, SearchInput, SelectInput, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { formatCurrency } from '@/utils/format'
import { budgetsApi, regimeLabel, type Dataset, type Group, type RefResult } from './api'
import { orderedGroups } from './tree'

interface Props {
  uuid: string
  number: number
  groups: Group[]
  /** Base do orçamento (pré-selecionada) e regime exigido (D-P03). */
  datasetId: number | null
  regime: string
  /** Etapa já escolhida (quando aberto pela linha da etapa). */
  initialGroupId?: number | null
  onClose: () => void
  /** keepOpen = "Adicionar e continuar" (lançamentos em sequência na mesma etapa). */
  onAdded: (keepOpen: boolean) => void
}

/** Busca composições/insumos na base de referência e adiciona como item (cópia autossuficiente — D-08). */
export function ReferencePicker({ uuid, number, groups, datasetId, regime, initialGroupId, onClose, onAdded }: Props) {
  const datasets = useQuery({ queryKey: ['reference-datasets'], queryFn: () => budgetsApi.references.datasets(), staleTime: 300_000 })
  const compatible = (datasets.data ?? []).filter((d: Dataset) => regime === 'not_applicable' || d.charges_regime === regime)
  const [dataset, setDataset] = useState<number | null>(datasetId)
  const [type, setType] = useState<'compositions' | 'resources'>('compositions')
  const [term, setTerm] = useState('')
  const [q, setQ] = useState('')
  const [chosen, setChosen] = useState<RefResult | null>(null)
  const tree = orderedGroups(groups)
  const [groupId, setGroupId] = useState(initialGroupId ? String(initialGroupId) : tree[0] ? String(tree[0].group.id) : '')
  const [keepOpen, setKeepOpen] = useState(false)
  const [quantity, setQuantity] = useState('1')
  const [error, setError] = useState<string | null>(null)

  const ds = dataset ?? compatible[0]?.id ?? null

  useEffect(() => {
    const t = setTimeout(() => setQ(term.trim()), 300)
    return () => clearTimeout(t)
  }, [term])

  const results = useQuery({
    queryKey: ['reference-search', ds, type, q],
    queryFn: () => budgetsApi.references.search(ds!, type, q),
    enabled: ds !== null && q.length >= 2,
  })

  const add = useMutation({
    mutationFn: () =>
      budgetsApi.items.fromReference(uuid, number, {
        reference_dataset_id: ds,
        type: type === 'compositions' ? 'composition' : 'resource',
        code: chosen!.code,
        group_id: Number(groupId),
        quantity,
      }),
    onSuccess: () => {
      setError(null)
      if (keepOpen) {
        setChosen(null)
        setQuantity('1')
      }
      onAdded(keepOpen)
    },
    onError: (e) => setError(getErrorMessage(e)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Adicionar da base de referência"
      description="O item é copiado com o custo publicado e a origem (fonte, competência, UF, regime)."
      size="xl"
      footer={
        <>
          <Button variant="outline" size="lg" onClick={onClose}>
            Cancelar
          </Button>
          <Button variant="outline" size="lg" disabled={!chosen || !groupId || !quantity || add.isPending} onClick={() => (setKeepOpen(true), add.mutate())}>
            Adicionar e continuar
          </Button>
          <Button size="lg" disabled={!chosen || !groupId || !quantity || add.isPending} onClick={() => (setKeepOpen(false), add.mutate())}>
            {add.isPending && <Loader2 className="size-4 animate-spin" />} Adicionar
          </Button>
        </>
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      {groups.length === 0 && (
        <Alert tone="warning" className="mb-3">
          Crie uma etapa antes de adicionar itens.
        </Alert>
      )}
      <div className="mb-3 grid gap-3 sm:grid-cols-2">
        <FormField label="Etapa onde o item será lançado" required>
          <SelectInput value={groupId} onChange={(e) => setGroupId(e.target.value)} disabled={groups.length === 0}>
            {tree.map(({ group, depth }) => (
              <option key={group.id} value={group.id}>
                {'\u00A0\u00A0'.repeat(depth)}
                {group.code} — {group.name}
              </option>
            ))}
          </SelectInput>
        </FormField>
        <FormField label="Base">
          <SelectInput value={ds ?? ''} onChange={(e) => setDataset(Number(e.target.value))}>
            {compatible.map((d) => (
              <option key={d.id} value={d.id}>
                {d.source?.code} {d.state} {d.competency} — {regimeLabel[d.charges_regime]}
              </option>
            ))}
          </SelectInput>
        </FormField>
      </div>
      <div className="mb-3 flex gap-1" role="tablist" aria-label="Tipo">
          {(['compositions', 'resources'] as const).map((t) => (
            <Button key={t} role="tab" aria-selected={type === t} variant={type === t ? 'default' : 'outline'} size="lg" onClick={() => (setType(t), setChosen(null))}>
              {t === 'compositions' ? 'Composições' : 'Insumos'}
            </Button>
          ))}
      </div>
      <SearchInput placeholder="Código ou palavras da descrição (mín. 2 caracteres)" aria-label="Buscar na base" value={term} onChange={(e) => setTerm(e.target.value)} autoFocus />

      <div className="mt-3 max-h-72 overflow-y-auto rounded-lg border">
        {q.length < 2 ? (
          <p className="text-muted-foreground p-4 text-sm">Digite para buscar.</p>
        ) : results.isPending ? (
          <p className="text-muted-foreground p-4 text-sm">Buscando…</p>
        ) : (results.data?.data ?? []).length === 0 ? (
          <p className="text-muted-foreground p-4 text-sm">Nada encontrado.</p>
        ) : (
          <ul className="divide-y" role="listbox" aria-label="Resultados">
            {results.data!.data.map((r) => {
              const price = r.total_cost ?? r.price
              return (
                <li key={r.code}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={chosen?.code === r.code}
                    onClick={() => setChosen(r)}
                    className={cn('hover:bg-primary-soft/50 flex w-full items-start gap-3 px-3 py-2 text-left text-sm', chosen?.code === r.code && 'bg-primary-soft')}
                  >
                    <span className="w-16 shrink-0 font-mono text-xs">{r.code}</span>
                    <span className="flex-1">{r.description}</span>
                    <span className="text-muted-foreground w-10 shrink-0">{r.unit}</span>
                    <span className="w-24 shrink-0 text-right tabular-nums">{price ? formatCurrency(Number(price)) : 'sem preço'}</span>
                    {chosen?.code === r.code && <Check className="text-primary size-4" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>

      {chosen && (
        <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_12rem]">
          <p className="self-end text-sm">
            <span className="font-mono text-xs">{chosen.code}</span> {chosen.description}
          </p>
          <FormField label={`Quantidade (${chosen.unit})`} required>
            <TextInput inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value.replace(',', '.'))} />
          </FormField>
        </div>
      )}
    </Modal>
  )
}
