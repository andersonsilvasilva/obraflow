import { Menu } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'

/**
 * Barra superior só em telas estreitas (padrão, ajuste 3): abre a gaveta do menu e mostra a organização ativa.
 * Em telas largas a organização e o usuário ficam na sidebar (G2) e o título na página (G4).
 */
export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { me } = useAuth()
  return (
    <header className="bg-card flex h-(--topbar-height) shrink-0 items-center gap-3 border-b px-3 lg:hidden">
      <Button variant="ghost" size="icon" onClick={onOpenMenu} aria-label="Abrir menu">
        <Menu className="size-5" />
      </Button>
      <img src="/brand/logo-simbolo.png" alt="ObraFlow" width={217} height={185} className="h-7 w-auto shrink-0" />
      <span className="truncate text-sm font-semibold">{me?.active_tenant?.name ?? 'ObraFlow'}</span>
    </header>
  )
}
