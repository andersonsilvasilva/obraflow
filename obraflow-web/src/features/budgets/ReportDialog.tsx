import { FileSpreadsheet, FileText, FileType2, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { Modal } from '@/components/feedback/Modal'
import { FormField, TextArea, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { saveBlob } from '@/lib/http'
import { cn } from '@/lib/utils'
import { budgetsApi } from './api'

type Format = 'pdf' | 'xlsx' | 'docx'
type Part = 'analytic' | 'bdi' | 'abc' | 'closing'

const FORMATS: { id: Format; label: string; hint: string; icon: typeof FileText }[] = [
  { id: 'pdf', label: 'PDF', hint: 'Documento final, para enviar e imprimir', icon: FileText },
  { id: 'xlsx', label: 'Excel (XLSX)', hint: 'Para conferir e trabalhar os números', icon: FileSpreadsheet },
  { id: 'docx', label: 'Word (DOCX)', hint: 'Para editar textos antes de enviar', icon: FileType2 },
]

const PARTS: { id: Part; label: string; hint: string }[] = [
  { id: 'analytic', label: 'Orçamento analítico', hint: 'A composição de cada item' },
  { id: 'bdi', label: 'BDI e encargos', hint: 'Parcelas do BDI, custos indiretos e regime' },
  { id: 'abc', label: 'Curva ABC', hint: 'Insumos e serviços, com gráfico' },
  { id: 'closing', label: 'Fechamento', hint: 'Observações, local, data e assinaturas' },
]

interface Props {
  uuid: string
  number: number
  code: string
  /** Versão aprovada? As demais saem com a marca d'água "RASCUNHO" (D-P82). */
  approved: boolean
  onClose: () => void
}

/**
 * Relatório do orçamento (4D): formato, partes e opções. Capa, resumo e sintético sempre entram. Observações e
 * dados do responsável valem só para este arquivo — não são gravados (D-P85).
 */
export function ReportDialog({ uuid, number, code, approved, onClose }: Props) {
  const { notify } = useToast()
  const [format, setFormat] = useState<Format>('pdf')
  const [parts, setParts] = useState<Part[]>(['analytic', 'bdi', 'abc', 'closing'])
  const [hideBdi, setHideBdi] = useState(false)
  const [closing, setClosing] = useState({ notes: '', place: '', technician_name: '', technician_registry: '', technician_art: '' })
  const [busy, setBusy] = useState(false)

  const toggle = (p: Part) => setParts((cur) => (cur.includes(p) ? cur.filter((x) => x !== p) : [...cur, p]))
  const has = (p: Part) => parts.includes(p) && !(p === 'bdi' && hideBdi)

  const generate = async () => {
    setBusy(true)
    try {
      const body = { format, parts: parts.filter((p) => has(p)), hide_bdi: hideBdi, ...(has('closing') ? closing : {}) }
      const { blob, filename } = await budgetsApi.report(uuid, number, body)
      saveBlob(blob, filename ?? `${code.replace(/[^A-Za-z0-9]+/g, '-')}-v${number}-orcamento.${format}`)
      notify('success', 'Relatório gerado', 'O download começou.')
      onClose()
    } catch (e) {
      notify('error', 'Não foi possível gerar o relatório', getErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={busy ? () => undefined : onClose}
      title="Relatório do orçamento"
      description={`${code} · versão ${number}${approved ? '' : ' · sairá com a marca d’água "RASCUNHO" (versão não aprovada)'}`}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Cancelar
          </Button>
          <Button onClick={generate} disabled={busy}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />} {busy ? 'Gerando…' : 'Gerar e baixar'}
          </Button>
        </>
      }
    >
      <fieldset className="mb-5">
        <legend className="mb-2 text-sm font-medium">Formato</legend>
        <div className="grid gap-2 sm:grid-cols-3">
          {FORMATS.map(({ id, label, hint, icon: Icon }) => (
            <label
              key={id}
              className={cn(
                'hover:border-primary/60 flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                format === id && 'border-primary bg-primary/5 ring-primary/30 ring-2',
              )}
            >
              <input type="radio" name="report-format" className="sr-only" checked={format === id} onChange={() => setFormat(id)} />
              <Icon className={cn('mt-0.5 size-5 shrink-0', format === id ? 'text-primary' : 'text-muted-foreground')} />
              <span>
                <span className="block text-sm font-medium">{label}</span>
                <span className="text-muted-foreground block text-xs">{hint}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="mb-5">
        <legend className="mb-1 text-sm font-medium">Partes</legend>
        <p className="text-muted-foreground mb-2 text-xs">Capa, resumo e orçamento sintético sempre entram.</p>
        <div className="grid gap-2 sm:grid-cols-2">
          {PARTS.map(({ id, label, hint }) => {
            const disabled = id === 'bdi' && hideBdi
            return (
              <label key={id} className={cn('flex items-start gap-2 rounded-md border p-2.5 text-sm', disabled && 'opacity-50')}>
                <input type="checkbox" className="accent-(--primary) mt-0.5 size-4" checked={has(id)} disabled={disabled} onChange={() => toggle(id)} />
                <span>
                  <span className="block font-medium">{label}</span>
                  <span className="text-muted-foreground block text-xs">{disabled ? 'Indisponível com o BDI oculto' : hint}</span>
                </span>
              </label>
            )
          })}
        </div>
      </fieldset>

      <fieldset className="mb-5">
        <legend className="mb-2 text-sm font-medium">Opções</legend>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="accent-(--primary) mt-0.5 size-4" checked={hideBdi} onChange={(e) => setHideBdi(e.target.checked)} />
          <span>
            <span className="block font-medium">Ocultar o BDI</span>
            <span className="text-muted-foreground block text-xs">O cliente vê só o preço unitário e o total. Lembre que o analítico mostra os custos das composições.</span>
          </span>
        </label>
      </fieldset>

      {has('closing') && (
        <fieldset>
          <legend className="mb-1 text-sm font-medium">Fechamento</legend>
          <p className="text-muted-foreground mb-3 text-xs">Valem só para este arquivo (não são gravados).</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="Observações" className="sm:col-span-2" hint="Ex.: validade da proposta, condições de pagamento, prazo.">
              <TextArea rows={3} maxLength={2000} value={closing.notes} onChange={(e) => setClosing({ ...closing, notes: e.target.value })} />
            </FormField>
            <FormField label="Local" hint="Vazio: cidade da obra.">
              <TextInput maxLength={120} value={closing.place} onChange={(e) => setClosing({ ...closing, place: e.target.value })} />
            </FormField>
            <FormField label="Responsável técnico" hint="Vazio: responsável técnico da obra.">
              <TextInput maxLength={150} value={closing.technician_name} onChange={(e) => setClosing({ ...closing, technician_name: e.target.value })} />
            </FormField>
            <FormField label="Registro (CREA/CAU)">
              <TextInput maxLength={60} value={closing.technician_registry} onChange={(e) => setClosing({ ...closing, technician_registry: e.target.value })} />
            </FormField>
            <FormField label="ART/RRT">
              <TextInput maxLength={60} value={closing.technician_art} onChange={(e) => setClosing({ ...closing, technician_art: e.target.value })} />
            </FormField>
          </div>
        </fieldset>
      )}
    </Modal>
  )
}
