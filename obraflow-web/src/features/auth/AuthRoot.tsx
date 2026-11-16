import { Outlet } from 'react-router-dom'
import { AuthProvider } from './AuthProvider'

/** Raiz das rotas: a sessão fica disponível para todas as telas (dentro do Router, para poder navegar). */
export function AuthRoot() {
  return (
    <AuthProvider>
      <Outlet />
    </AuthProvider>
  )
}
