import { Bell, CircleHelp, Menu } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { SearchInput, SelectInput } from '@/components/forms/fields'
import { demoCompanies, demoProjectsFilter, demoUser } from '@/mocks/session'

/** Seletores e perfil são demonstrativos nesta etapa (sem autenticação real). */
export function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  return (
    <header className="bg-card flex h-(--topbar-height) shrink-0 items-center gap-3 border-b px-3 sm:px-4">
      <Button variant="ghost" size="icon" className="lg:hidden" onClick={onOpenMenu} aria-label="Abrir menu">
        <Menu className="size-5" />
      </Button>

      <div className="hidden md:block">
        <span className="text-muted-foreground block text-[11px] leading-none">Organização</span>
        <span className="text-sm font-semibold">{demoUser.tenant}</span>
      </div>

      <label className="sr-only" htmlFor="company-select">Empresa</label>
      <SelectInput id="company-select" className="hidden w-44 lg:block" defaultValue={demoCompanies[0].id}>
        {demoCompanies.map((c) => (
          <option key={c.id} value={c.id}>{c.name}</option>
        ))}
      </SelectInput>

      <label className="sr-only" htmlFor="project-select">Obra</label>
      <SelectInput id="project-select" className="hidden w-48 xl:block" defaultValue="">
        <option value="">Todas as obras</option>
        {demoProjectsFilter.map((p) => (
          <option key={p.id} value={p.id}>{p.name}</option>
        ))}
      </SelectInput>

      <SearchInput className="ml-auto w-full max-w-xs" placeholder="Buscar no sistema…" aria-label="Busca global" />

      <Button variant="ghost" size="icon" aria-label="Notificações (3 novas)" className="relative">
        <Bell className="size-5" />
        <span className="bg-primary absolute top-1.5 right-1.5 size-2 rounded-full" aria-hidden />
      </Button>
      <Button variant="ghost" size="icon" aria-label="Ajuda" className="hidden sm:inline-flex">
        <CircleHelp className="size-5" />
      </Button>

      <button type="button" className="hover:bg-muted flex items-center gap-2 rounded-lg p-1 pr-2" aria-label={`Perfil de ${demoUser.name}`}>
        <span className="bg-primary text-primary-foreground flex size-8 items-center justify-center rounded-full text-xs font-semibold" aria-hidden>
          {demoUser.initials}
        </span>
        <span className="hidden text-left sm:block">
          <span className="block text-sm leading-tight font-medium">{demoUser.name}</span>
          <span className="text-muted-foreground block text-[11px] leading-tight">{demoUser.role}</span>
        </span>
      </button>
    </header>
  )
}
