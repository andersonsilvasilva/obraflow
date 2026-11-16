import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Pencil, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { LoadingState } from '@/components/feedback/states'
import { TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { getErrorMessage } from '@/lib/api-error'

export interface QuickAddConfig {
  title: string
  queryKey: string
  managePermission: string
  list: () => Promise<{ id: number; name: string }[]>
  create: (name: string) => Promise<{ id: number }>
  rename: (id: number, name: string) => Promise<unknown>
  remove: (id: number) => Promise<unknown>
}

/**
 * Cadastro rápido de entidade auxiliar (F4): campo + "Adicionar" na mesma linha e a lista com editar/excluir,
 * num modal sobre o formulário — o que já foi digitado não se perde. Clicar num item o seleciona.
 */
export function CatalogQuickModal({ config, onClose, onPick }: { config: QuickAddConfig; onClose: () => void; onPick: (id: number) => void }) {
  const client = useQueryClient()
  const { can } = useAuth()
  const canManage = can(config.managePermission)
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const list = useQuery({ queryKey: [config.queryKey, 'quick'], queryFn: config.list })
  const refresh = () => client.invalidateQueries({ queryKey: [config.queryKey] })
  const onError = (e: unknown) => setError(getErrorMessage(e))

  const add = useMutation({
    mutationFn: () => config.create(name.trim()),
    onSuccess: (item) => {
      setName('')
      setError(null)
      refresh()
      onPick(item.id)
    },
    onError,
  })
  const rename = useMutation({
    mutationFn: (v: { id: number; name: string }) => config.rename(v.id, v.name.trim()),
    onSuccess: () => {
      setEditing(null)
      setError(null)
      refresh()
    },
    onError,
  })
  const remove = useMutation({ mutationFn: (id: number) => config.remove(id), onSuccess: () => (setError(null), refresh()), onError })

  return (
    <Modal open onClose={onClose} title={config.title} size="md">
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      <form
        className="mb-3 flex gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          if (name.trim()) add.mutate()
        }}
      >
        <label className="sr-only" htmlFor="quick-name">
          Nome
        </label>
        <TextInput id="quick-name" placeholder="Nome" value={name} onChange={(e) => setName(e.target.value)} disabled={!canManage} />
        <Button type="submit" size="lg" variant="outline" disabled={!canManage || !name.trim() || add.isPending}>
          Adicionar
        </Button>
      </form>
      {list.isPending ? (
        <LoadingState />
      ) : (
        <ul className="divide-y">
          {(list.data ?? []).map((item) => (
            <li key={item.id} className="flex items-center gap-2 py-1.5">
              {editing?.id === item.id ? (
                <>
                  <TextInput aria-label="Novo nome" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} autoFocus />
                  <Button variant="ghost" size="icon" aria-label="Salvar" onClick={() => rename.mutate(editing)}>
                    <Check className="size-4" />
                  </Button>
                  <Button variant="ghost" size="icon" aria-label="Cancelar" onClick={() => setEditing(null)}>
                    <X className="size-4" />
                  </Button>
                </>
              ) : (
                <>
                  <button type="button" className="hover:text-primary flex-1 text-left text-sm" onClick={() => onPick(item.id)} title="Selecionar">
                    {item.name}
                  </button>
                  <Button variant="ghost" size="icon" aria-label={`Editar ${item.name}`} disabled={!canManage} onClick={() => setEditing({ id: item.id, name: item.name })}>
                    <Pencil className="size-4" />
                  </Button>
                  <Button variant="ghost" size="icon" aria-label={`Excluir ${item.name}`} disabled={!canManage} onClick={() => remove.mutate(item.id)}>
                    <Trash2 className="size-4" />
                  </Button>
                </>
              )}
            </li>
          ))}
          {list.data?.length === 0 && <li className="text-muted-foreground py-3 text-sm">Nenhum item cadastrado.</li>}
        </ul>
      )}
    </Modal>
  )
}
