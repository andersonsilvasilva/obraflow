import { http } from '@/lib/http'

export interface TenantSummary {
  uuid: string
  name: string
  slug: string
  status: string
}

/** Resposta de /auth/me, do login e da troca de organização (MePayload no backend). */
export interface Me {
  user: { uuid: string; name: string; email: string }
  tenants: TenantSummary[]
  active_tenant: TenantSummary | null
  /** Permissões no tenant ativo (null sem tenant ativo). */
  permissions: string[] | null
}

export const authService = {
  me: () => http<Me>('/auth/me'),
  login: (email: string, password: string) => http<Me>('/auth/login', { method: 'POST', body: { email, password } }),
  logout: () => http<void>('/auth/logout', { method: 'POST' }),
  selectTenant: (tenantUuid: string) => http<Me>('/session/tenant', { method: 'POST', body: { tenant_uuid: tenantUuid } }),
  forgotPassword: (email: string) => http<{ message: string }>('/auth/forgot-password', { method: 'POST', body: { email } }),
}
