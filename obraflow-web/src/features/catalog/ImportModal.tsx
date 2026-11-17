import { useMutation } from '@tanstack/react-query'
import { FileUp, Loader2, Upload } from 'lucide-react'
import { useState } from 'react'
import { StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { catalogApi, type ImportPreview } from './api'

/** Botão de importação (ícone de seta para cima, como na tela de referência) para materiais e equipamentos. */
export function ImportButton({ kind, canManage, onDone }: { kind: 'material' | 'equipment'; canManage: boolean; onDone: () => void }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="outline" size="lg" disabled={!canManage} title={canManage ? 'Importar planilha' : 'Sem permissão'} onClick={() => setOpen(true)}>
        <Upload className="size-4" /> Importar
      </Button>
      {open && <ImportModal kind={kind} onClose={() => setOpen(false)} onDone={onDone} />}
    </>
  )
}

const tone = { ok: 'success', skip: 'neutral', error: 'danger' } as const
const label = { ok: 'Será importado', skip: 'Ignorado', error: 'Com erro' } as const

/**
 * Importação em duas etapas (D-P43): envia a planilha e mostra a pré-visualização (nada é gravado); ao confirmar,
 * o MESMO arquivo é enviado e só as linhas válidas são gravadas.
 */
function ImportModal({ kind, onClose, onDone }: { kind: 'material' | 'equipment'; onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [result, setResult] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const check = useMutation({
    mutationFn: (f: File) => catalogApi.importPreview(kind, f),
    onSuccess: (p) => (setPreview(p), setError(null)),
    onError: (e) => (setPreview(null), setError(getErrorMessage(e))),
  })
  const run = useMutation({
    mutationFn: () => catalogApi.import(kind, file!),
    onSuccess: (r) => {
      setResult(r.created)
      onDone()
    },
    onError: (e) => setError(getErrorMessage(e)),
  })

  const template = kind === 'material' ? 'product.xlsx (Subgrupo, Grupo*, Nome*, Marca, Fornecedor principal, Unidade de medida*)' : 'equipment.xlsx (Subgrupo, Grupo*, Nome*, Código, Marca, Modelo, Tipo, Condição, Unidade de medida, Fornecedor principal)'

  return (
    <Modal
      open
      onClose={onClose}
      title={`Importar ${kind === 'material' ? 'materiais' : 'equipamentos'}`}
      description={`Planilha-modelo: ${template}`}
      size="xl"
      footer={
        result !== null ? (
          <Button size="lg" onClick={onClose}>
            Fechar
          </Button>
        ) : (
          <>
            <Button variant="outline" size="lg" onClick={onClose}>
              Cancelar
            </Button>
            <Button size="lg" disabled={!preview || preview.summary.ok === 0 || run.isPending} onClick={() => run.mutate()}>
              {run.isPending && <Loader2 className="size-4 animate-spin" />} Importar {preview ? `${preview.summary.ok} linha(s)` : ''}
            </Button>
          </>
        )
      }
    >
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      {result !== null ? (
        <Alert tone="success">{result} insumo(s) importado(s). Linhas ignoradas ou com erro não foram gravadas.</Alert>
      ) : (
        <>
          <label className="hover:border-primary flex cursor-pointer items-center gap-3 rounded-lg border border-dashed p-4 text-sm">
            <FileUp className="text-primary size-6" aria-hidden />
            <span className="flex-1">{file ? file.name : 'Escolha a planilha (.xlsx, até 5 MB)'}</span>
            {check.isPending && <Loader2 className="size-4 animate-spin" />}
            <input
              type="file"
              accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              className="sr-only"
              onChange={(e) => {
                const f = e.target.files?.[0] ?? null
                setFile(f)
                setPreview(null)
                if (f) check.mutate(f)
              }}
            />
          </label>

          {preview && (
            <div className="mt-4">
              <p className="mb-2 text-sm">
                <strong>{preview.summary.total}</strong> linha(s): <strong className="text-success-strong">{preview.summary.ok}</strong> serão importadas,{' '}
                {preview.summary.skip} ignoradas, <strong className="text-destructive">{preview.summary.error}</strong> com erro. Nada foi gravado ainda.
              </p>
              <div className="max-h-80 overflow-auto rounded-lg border">
                <table className="w-full text-xs">
                  <caption className="sr-only">Pré-visualização</caption>
                  <thead className="bg-muted sticky top-0">
                    <tr className="text-left">
                      {['Linha', 'Nome', 'Grupo', 'Subgrupo', 'Unidade', 'Situação', 'Observações'].map((h) => (
                        <th key={h} scope="col" className="px-2 py-1.5 font-semibold">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.rows.map((r) => (
                      <tr key={r.line} className="border-b align-top">
                        <td className="px-2 py-1.5">{r.line}</td>
                        <td className="px-2 py-1.5">{r.data.name}</td>
                        <td className="px-2 py-1.5">{r.data.group}</td>
                        <td className="px-2 py-1.5">{r.data.subgroup}</td>
                        <td className="px-2 py-1.5">{r.data.unit}</td>
                        <td className="px-2 py-1.5">
                          <StatusBadge tone={tone[r.status]}>{label[r.status]}</StatusBadge>
                        </td>
                        <td className="px-2 py-1.5">
                          {[...r.errors, ...r.warnings, ...r.actions].map((m, i) => (
                            <p key={i} className={r.errors.includes(m) ? 'text-destructive' : r.actions.includes(m) ? 'text-primary' : 'text-muted-foreground'}>
                              {m}
                            </p>
                          ))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </Modal>
  )
}
