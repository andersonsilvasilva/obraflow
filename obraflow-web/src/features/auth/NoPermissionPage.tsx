import { ShieldAlert } from 'lucide-react'
import { Link } from 'react-router-dom'

export function NoPermissionPage() {
  return (
    <div className="bg-card flex flex-col items-center gap-3 rounded-xl border py-20 text-center">
      <div className="bg-warning-soft text-warning-strong rounded-full p-3">
        <ShieldAlert className="size-6" aria-hidden />
      </div>
      <h1 className="text-lg font-semibold">Acesso não permitido</h1>
      <p className="text-muted-foreground max-w-md text-sm">Seu perfil nesta organização não tem permissão para esta tela. Fale com o administrador.</p>
      <Link to="/" className="text-primary text-sm hover:underline">
        Voltar ao início
      </Link>
    </div>
  )
}
