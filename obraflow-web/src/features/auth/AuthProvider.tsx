import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { ApiError } from '@/lib/api-error'
import { onUnauthorized } from '@/lib/http'
import { setActiveTenant } from '@/lib/tenant-context'
import { AuthContext, type AuthContextValue } from './auth-context'
import { authService, type Me } from './auth-service'

const ME_KEY = ['auth', 'me'] as const

/**
 * Sessão do usuário (T-02). A organização ativa e as permissões vêm SEMPRE do servidor (/auth/me);
 * o navegador só registra qual organização esta aba exibe (X-Tenant-Hint).
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()

  const query = useQuery({
    queryKey: ME_KEY,
    queryFn: async () => {
      try {
        return await authService.me()
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  const apply = useCallback(
    (me: Me | null) => {
      setActiveTenant(me?.active_tenant?.uuid ?? null)
      client.setQueryData(ME_KEY, me)
    },
    [client],
  )

  useEffect(() => {
    if (query.data !== undefined) setActiveTenant(query.data?.active_tenant?.uuid ?? null)
  }, [query.data])

  // sessão expirada em qualquer chamada → volta ao login
  useEffect(() => onUnauthorized(() => client.setQueryData(ME_KEY, null)), [client])

  const value = useMemo<AuthContextValue>(() => {
    const permissions = new Set(query.data?.permissions ?? [])
    return {
      me: query.isPending ? undefined : (query.data ?? null),
      can: (p) => permissions.has(p),
      login: async (email, password) => {
        const me = await authService.login(email, password)
        apply(me)
        return me
      },
      logout: async () => {
        try {
          await authService.logout()
        } finally {
          client.clear()
          apply(null)
        }
      },
      selectTenant: async (uuid) => {
        const me = await authService.selectTenant(uuid)
        // dados de outra organização não podem sobreviver à troca
        client.removeQueries({ predicate: (q) => q.queryKey[0] !== 'auth' })
        apply(me)
        return me
      },
    }
  }, [query.data, query.isPending, apply, client])

  if (query.isError) throw query.error

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

