import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { LoadingState } from '@/components/feedback/states'
import { useAuth } from './auth-context'

/** Exige sessão e organização ativa; sem sessão → /login; sem organização → /organizacao. */
export function RequireAuth() {
  const { me } = useAuth()
  const location = useLocation()

  if (me === undefined) return <LoadingState label="Verificando sessão…" />
  if (me === null) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (!me.active_tenant) return <Navigate to="/organizacao" replace />
  return <Outlet />
}

/** Só para quem NÃO está logado (tela de login). */
export function GuestOnly() {
  const { me } = useAuth()
  if (me === undefined) return <LoadingState label="Verificando sessão…" />
  if (me) return <Navigate to={me.active_tenant ? '/' : '/organizacao'} replace />
  return <Outlet />
}

/** Bloqueia a rota sem a permissão de consulta (a API também recusaria com 403). */
export function RequirePermission({ permission }: { permission: string }) {
  const { can } = useAuth()
  if (!can(permission)) return <Navigate to="/sem-permissao" replace />
  return <Outlet />
}
