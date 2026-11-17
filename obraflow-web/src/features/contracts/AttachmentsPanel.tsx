import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileUp, Loader2, Paperclip } from 'lucide-react'
import { useRef, useState } from 'react'
import { useToast } from '@/app/providers/ToastProvider'
import { TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { getErrorMessage } from '@/lib/api-error'
import { formatDateOrDash } from '@/utils/format'
import { attachmentsApi } from './api'

interface Props {
  type: 'contract' | 'contract_addendum'
  /** uuid do contrato ou id do aditivo */
  linkKey: string
  canEdit: boolean
  /** Título sugerido para o arquivo (ex.: "Contrato CTR-001/2026 assinado"). */
  suggestedTitle: string
  compact?: boolean
}

const size = (b: number) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

/**
 * Arquivos do contrato ou do aditivo (D-P55): ficam nos Documentos (disco privado), ligados ao dono. Download por
 * link temporário (D-P15). Enviar exige poder editar o contrato e `documents.manage`.
 */
export function AttachmentsPanel({ type, linkKey, canEdit, suggestedTitle, compact }: Props) {
  const { can } = useAuth()
  const { notify } = useToast()
  const client = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const [title, setTitle] = useState(suggestedTitle)
  const key = ['attachments', type, linkKey]
  const list = useQuery({ queryKey: key, queryFn: () => attachmentsApi.list(type, linkKey), enabled: can('documents.view') })

  const upload = useMutation({
    mutationFn: (file: File) => attachmentsApi.upload(type, linkKey, title.trim() || file.name, file),
    onSuccess: () => {
      notify('success', 'Arquivo anexado.')
      client.invalidateQueries({ queryKey: key })
      client.invalidateQueries({ queryKey: ['contract'] })
      if (input.current) input.current.value = ''
    },
    onError: (e) => notify('error', getErrorMessage(e)),
  })

  const open = (uuid: string, version: number) =>
    attachmentsApi
      .url(uuid, version)
      .then((r) => window.open(r.url, '_blank', 'noopener'))
      .catch((e) => notify('error', getErrorMessage(e)))

  if (!can('documents.view')) return <p className="text-muted-foreground text-sm">Sem permissão para ver documentos.</p>
  const docs = list.data ?? []

  return (
    <div className="space-y-3">
      {list.isPending ? (
        <p className="text-muted-foreground text-sm">Carregando arquivos…</p>
      ) : docs.length === 0 ? (
        <p className="text-muted-foreground text-sm">Nenhum arquivo anexado.</p>
      ) : (
        <ul className="divide-y rounded-lg border">
          {docs.map((d) => (
            <li key={d.uuid} className="flex items-center gap-3 px-3 py-2 text-sm">
              <Paperclip className="text-muted-foreground size-4 shrink-0" aria-hidden />
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{d.title}</p>
                <p className="text-muted-foreground truncate text-xs">
                  {d.current_version ? `${d.current_version.original_name} · ${size(d.current_version.size_bytes)} · ` : 'sem arquivo · '}
                  {formatDateOrDash(d.created_at)}
                </p>
              </div>
              {d.current_version && (
                <Button variant="ghost" size="icon-sm" aria-label={`Baixar ${d.title}`} title="Baixar" onClick={() => open(d.uuid, d.current_version!.version_number)}>
                  <Download />
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
      {canEdit && can('documents.manage') && (
        <div className={compact ? 'flex flex-wrap items-center gap-2' : 'flex flex-wrap items-end gap-2'}>
          <TextInput className="min-w-56 flex-1" aria-label="Título do arquivo" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} />
          <input
            ref={input}
            type="file"
            className="hidden"
            accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) upload.mutate(f)
            }}
          />
          <Button variant="outline" size="lg" disabled={upload.isPending} onClick={() => input.current?.click()}>
            {upload.isPending ? <Loader2 className="size-4 animate-spin" /> : <FileUp className="size-4" />} Anexar arquivo
          </Button>
        </div>
      )}
    </div>
  )
}
