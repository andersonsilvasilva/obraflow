import { createContext, useContext } from 'react'
import type { Me } from './auth-service'

export interface AuthContextValue {
  /** undefined = carregando; null = sem sessão */
  me: Me | null | undefined
  can: (permission: string) => boolean
  login: (email: string, password: string) => Promise<Me>
  logout: () => Promise<void>
  selectTenant: (uuid: string) => Promise<Me>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth fora do AuthProvider')
  return ctx
}