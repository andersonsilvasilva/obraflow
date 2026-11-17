import { Building2, Calculator, CalendarDays, HardHat } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useAuth } from '@/features/auth/auth-context'

const dateFmt = new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

function greeting(hour: number): string {
  if (hour < 12) return 'Bom dia'
  if (hour < 18) return 'Boa tarde'
  return 'Boa noite'
}

/**
 * Abertura do Visão geral: logomarca, saudação, organização e data, com atalhos. Fundo claro com degradê suave
 * em azul-safira e o símbolo como marca d'água — o "Obra" da logo é azul-marinho, então a logo fica sobre fundo
 * claro (DESIGN_SYSTEM.md, Logomarca). Só tokens: nenhuma cor fixa.
 */
export function DashboardHero() {
  const { me, can } = useAuth()
  const [now] = useState(() => new Date())
  const firstName = me?.user?.name?.split(' ')[0]
  const today = dateFmt.format(now)

  return (
    <section
      aria-label="Boas-vindas"
      className="relative mb-5 overflow-hidden rounded-xl border bg-linear-to-br from-(--card) via-(--card) to-(--primary-soft) shadow-(--shadow-card)"
    >
      {/* marca d'água: símbolo da marca, decorativo */}
      <img
        src="/brand/logo-simbolo.png"
        alt=""
        aria-hidden
        width={217}
        height={185}
        className="pointer-events-none absolute -right-6 -bottom-10 hidden h-56 w-auto opacity-[0.08] select-none md:block"
      />
      <div className="absolute inset-x-0 top-0 h-1 bg-linear-to-r from-(--primary-deep) via-(--primary) to-(--chart-3)" aria-hidden />

      <div className="relative flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
          <img src="/brand/logo-completa.png" alt="ObraFlow" width={477} height={109} className="h-12 w-auto shrink-0 sm:h-14" />
          <div className="bg-border hidden h-14 w-px sm:block" aria-hidden />
          <div className="min-w-0">
            <h1 className="text-2xl font-semibold tracking-tight">
              {greeting(now.getHours())}
              {firstName ? `, ${firstName}` : ''}
            </h1>
            <p className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              {me?.active_tenant?.name && (
                <span className="inline-flex items-center gap-1.5">
                  <Building2 className="size-4" aria-hidden /> {me.active_tenant.name}
                </span>
              )}
              <span className="inline-flex items-center gap-1.5">
                <CalendarDays className="size-4" aria-hidden /> <span className="inline-block first-letter:uppercase">{today}</span>
              </span>
            </p>
            <p className="text-muted-foreground mt-2 text-sm">Indicadores executivos das obras, orçamentos e finanças.</p>
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          {can('projects.view') && (
            <Link to="/obras" className={buttonVariants({ size: 'lg' })}>
              <HardHat className="size-4" /> Obras
            </Link>
          )}
          {can('budgets.view') && (
            <Link to="/orcamentos" className={cn(buttonVariants({ size: 'lg', variant: 'outline' }), 'bg-(--card)')}>
              <Calculator className="size-4" /> Orçamentos
            </Link>
          )}
        </div>
      </div>
    </section>
  )
}
