import { useState } from 'react'
import { Modal } from '@/components/feedback/Modal'
import { FormField, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const PARTS = [
  ['ac', 'Administração central (AC)'],
  ['s', 'Seguro (S)'],
  ['r', 'Risco (R)'],
  ['g', 'Garantia (G)'],
  ['df', 'Despesas financeiras (DF)'],
  ['l', 'Lucro (L)'],
  ['i', 'Tributos (I)'],
] as const

/** Prévia da fórmula do TCU (o valor gravado é o calculado no servidor, com aritmética exata). */
function tcuPreview(c: Record<string, string>): number | null {
  const p = (k: string) => Number((c[k] ?? '0').replace(',', '.')) / 100
  if (PARTS.some(([k]) => Number.isNaN(p(k)))) return null
  if (p('i') >= 1) return null
  return (((1 + p('ac') + p('s') + p('r') + p('g')) * (1 + p('df')) * (1 + p('l'))) / (1 - p('i')) - 1) * 100
}

interface Props {
  mode: 'bdi' | 'discount'
  bdiPercent: string
  bdiComponents: Record<string, string> | null
  discountPercent: string
  canEdit: boolean
  onClose: () => void
  onSave: (body: Record<string, unknown>) => void
}

/**
 * BDI e desconto do planejamento (6B, D-P92): venda = custo × (1 + BDI) × (1 − desconto), arredondada por recurso.
 * O BDI pode ser informado ou calculado pela fórmula do TCU (como no orçamento). Cada recurso pode ter exceção própria.
 */
export function PricingDialog({ mode, bdiPercent, bdiComponents, discountPercent, canEdit, onClose, onSave }: Props) {
  const [method, setMethod] = useState<'informed' | 'tcu'>(bdiComponents ? 'tcu' : 'informed')
  const [percent, setPercent] = useState(String(Number(bdiPercent)))
  const [parts, setParts] = useState<Record<string, string>>(() => Object.fromEntries(PARTS.map(([k]) => [k, String(Number(bdiComponents?.[k] ?? 0))])))
  const [discount, setDiscount] = useState(String(Number(discountPercent)))
  const preview = method === 'tcu' ? tcuPreview(parts) : null
  const dec = (v: string) => v.replace(',', '.')

  const save = () =>
    onSave(
      mode === 'discount'
        ? { discount_percent: dec(discount) || '0' }
        : method === 'tcu'
          ? { bdi_method: 'tcu', bdi_components: Object.fromEntries(Object.entries(parts).map(([k, v]) => [k, dec(v) || '0'])) }
          : { bdi_method: 'informed', bdi_percent: dec(percent) || '0' },
    )

  return (
    <Modal
      open
      onClose={onClose}
      title={mode === 'bdi' ? 'BDI do planejamento' : 'Desconto do planejamento'}
      description={
        mode === 'bdi'
          ? 'Vale para todos os recursos que não têm BDI próprio. Venda = custo × (1 + BDI) × (1 − desconto).'
          : 'Desconto sobre o preço com BDI, para todos os recursos que não têm desconto próprio.'
      }
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={save} disabled={!canEdit}>
            Salvar
          </Button>
        </>
      }
    >
      {mode === 'discount' ? (
        <FormField label="Desconto (%)">
          <TextInput inputMode="decimal" value={discount} onChange={(e) => setDiscount(e.target.value)} disabled={!canEdit} />
        </FormField>
      ) : (
        <>
          <div className="mb-4 flex gap-2" role="radiogroup" aria-label="Como definir o BDI">
            {(
              [
                ['informed', 'Informar o percentual'],
                ['tcu', 'Fórmula do TCU'],
              ] as const
            ).map(([m, label]) => (
              <button
                key={m}
                type="button"
                role="radio"
                aria-checked={method === m}
                disabled={!canEdit}
                onClick={() => setMethod(m)}
                className={cn('flex-1 rounded-md border px-3 py-2 text-sm', method === m ? 'border-primary bg-primary/10 text-primary font-medium' : 'text-muted-foreground')}
              >
                {label}
              </button>
            ))}
          </div>
          {method === 'informed' ? (
            <FormField label="BDI (%)">
              <TextInput inputMode="decimal" value={percent} onChange={(e) => setPercent(e.target.value)} disabled={!canEdit} />
            </FormField>
          ) : (
            <>
              <p className="bg-muted text-foreground mb-3 rounded-md px-3 py-2 text-xs">BDI = [(1 + AC + S + R + G) × (1 + DF) × (1 + L) ÷ (1 − I)] − 1</p>
              <div className="grid grid-cols-2 gap-3">
                {PARTS.map(([k, label]) => (
                  <FormField key={k} label={`${label} %`}>
                    <TextInput inputMode="decimal" value={parts[k]} onChange={(e) => setParts({ ...parts, [k]: e.target.value })} disabled={!canEdit} />
                  </FormField>
                ))}
              </div>
              <p className="mt-3 text-sm">
                BDI resultante: <strong>{preview === null ? '—' : `${preview.toLocaleString('pt-BR', { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`}</strong>
              </p>
            </>
          )}
        </>
      )}
    </Modal>
  )
}
