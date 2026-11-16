import { Building2, Loader2, LogOut } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert } from '@/components/feedback/Alert'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'
import { useAuth } from './auth-context'

/** Escolha da organização quando o usuário tem mais de um vínculo (ou nenhum ativo). */
export function SelectTenantPage() {
  const { me, selectTenant, logout } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const choose = async (uuid: string) => {
    setBusy(uuid)
    setError(null)
    try {
      await selectTenant(uuid)
      navigate('/', { replace: true })
    } catch (e) {
      setError(getErrorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  const tenants = me?.tenants ?? []

  return (
    <main className="bg-background flex min-h-dvh items-center justify-center p-4">
      <div className="bg-card shadow-card w-full max-w-md rounded-xl border p-6">
        <h1 className="mb-1 text-lg font-semibold">Escolha a organização</h1>
        <p className="text-muted-foreground mb-5 text-sm">Olá, {me?.user.name}. Em qual organização deseja trabalhar?</p>
        {error && <Alert tone="danger" className="mb-4">{error}</Alert>}
        {tenants.length === 0 ? (
          <Alert tone="warning">Você não tem acesso ativo a nenhuma organização. Procure o administrador.</Alert>
        ) : (
          <ul className="flex flex-col gap-2">
            {tenants.map((t) => (
              <li key={t.uuid}>
                <button
                  type="button"
                  disabled={busy !== null || t.status !== 'active'}
                  onClick={() => choose(t.uuid)}
                  className="hover:border-primary hover:bg-primary-soft/40 flex w-full items-center gap-3 rounded-lg border p-3 text-left disabled:opacity-50"
                >
                  <Building2 className="text-primary size-5" aria-hidden />
                  <span className="flex-1 font-medium">{t.name}</span>
                  {t.status !== 'active' && <span className="text-muted-foreground text-xs">indisponível</span>}
                  {busy === t.uuid && <Loader2 className="size-4 animate-spin" aria-hidden />}
                </button>
              </li>
            ))}
          </ul>
        )}
        <Button variant="ghost" className="mt-5" onClick={() => logout().then(() => navigate('/login', { replace: true }))}>
          <LogOut className="size-4" /> Sair
        </Button>
      </div>
    </main>
  )
}
