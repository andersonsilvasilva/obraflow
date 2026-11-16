import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderOpen, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { LoadingState } from '@/components/feedback/states'
import { FormField, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { ApiError, getErrorMessage } from '@/lib/api-error'
import { formatDateOrDash } from '@/utils/format'
import { api, type Employee } from './api'

/** Ícone de pasta na linha do funcionário (L4): certificações e treinamentos com vencimento. */
export function CertificationsButton({ employee }: { employee: Employee }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="ghost" size="icon" aria-label={`Certificações de ${employee.name}`} title="Certificações e treinamentos" onClick={() => setOpen(true)}>
        <FolderOpen className="size-4" />
      </Button>
      {open && <CertificationsModal employee={employee} onClose={() => setOpen(false)} />}
    </>
  )
}

function CertificationsModal({ employee, onClose }: { employee: Employee; onClose: () => void }) {
  const { can } = useAuth()
  const canManage = can('employees.manage')
  const client = useQueryClient()
  const svc = api.certifications(employee.uuid)
  const key = ['certifications', employee.uuid]
  const [form, setForm] = useState({ name: '', issued_at: '', expires_at: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  const list = useQuery({ queryKey: key, queryFn: () => svc.all() })
  const add = useMutation({
    mutationFn: () => svc.create({ name: form.name.trim(), issued_at: form.issued_at || null, expires_at: form.expires_at || null }),
    onSuccess: () => {
      setForm({ name: '', issued_at: '', expires_at: '' })
      setErrors({})
      setError(null)
      client.invalidateQueries({ queryKey: key })
    },
    onError: (e) => {
      if (e instanceof ApiError && e.details) setErrors(Object.fromEntries(Object.entries(e.details).map(([k, v]) => [k, v[0]])))
      else setError(getErrorMessage(e))
    },
  })
  const remove = useMutation({
    mutationFn: (id: number) => svc.remove(id),
    onSuccess: () => client.invalidateQueries({ queryKey: key }),
    onError: (e) => setError(getErrorMessage(e)),
  })

  return (
    <Modal open onClose={onClose} title="Certificações e treinamentos" description={employee.name} size="lg">
      {error && (
        <Alert tone="danger" className="mb-3">
          {error}
        </Alert>
      )}
      {canManage && (
        <form
          className="mb-4 grid items-end gap-3 sm:grid-cols-[1fr_9rem_9rem_auto]"
          onSubmit={(e) => {
            e.preventDefault()
            if (form.name.trim()) add.mutate()
          }}
        >
          <FormField label="Nome" required error={errors.name}>
            <TextInput placeholder="Ex.: NR-35" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </FormField>
          <FormField label="Emissão" error={errors.issued_at}>
            <TextInput type="date" value={form.issued_at} onChange={(e) => setForm({ ...form, issued_at: e.target.value })} />
          </FormField>
          <FormField label="Vencimento" error={errors.expires_at}>
            <TextInput type="date" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} />
          </FormField>
          <Button type="submit" size="lg" disabled={!form.name.trim() || add.isPending}>
            Adicionar
          </Button>
        </form>
      )}
      {list.isPending ? (
        <LoadingState />
      ) : (list.data ?? []).length === 0 ? (
        <p className="text-muted-foreground py-6 text-center text-sm">Nenhuma certificação cadastrada.</p>
      ) : (
        <table className="w-full text-sm">
          <caption className="sr-only">Certificações</caption>
          <thead className="bg-muted">
            <tr className="text-left">
              <th scope="col" className="px-3 py-2 font-semibold">Nome</th>
              <th scope="col" className="px-3 py-2 font-semibold">Emissão</th>
              <th scope="col" className="px-3 py-2 font-semibold">Vencimento</th>
              <th scope="col" className="px-3 py-2"><span className="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            {list.data!.map((c) => (
              <tr key={c.id} className="border-b last:border-0">
                <td className="px-3 py-2 font-medium">{c.name}</td>
                <td className="px-3 py-2">{formatDateOrDash(c.issued_at)}</td>
                <td className="px-3 py-2">
                  {formatDateOrDash(c.expires_at)} {c.expired && <StatusBadge tone="danger">Vencida</StatusBadge>}
                </td>
                <td className="px-3 py-1 text-right">
                  <Button variant="ghost" size="icon" aria-label={`Excluir ${c.name}`} disabled={!canManage} onClick={() => remove.mutate(c.id)}>
                    <Trash2 className="size-4" />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  )
}
