import { useQueries } from '@tanstack/react-query'
import { ArrowRight, CheckCircle2, Circle, Lock, Loader2, Lightbulb } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Panel } from '@/components/data-display/DataTable'
import { StatusBadge } from '@/components/data-display/indicators'
import { Alert } from '@/components/feedback/Alert'
import { PageHeader } from '@/components/layout/PageHeader'
import { buttonVariants } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { http } from '@/lib/http'
import { cn } from '@/lib/utils'
import { nextStep, sheetDone, stepState, steps, type StepState } from './steps'

const badge: Record<StepState, { label: string; tone: 'success' | 'primary' | 'neutral' | 'warning' }> = {
  done: { label: 'Feito', tone: 'success' },
  pending: { label: 'Pendente', tone: 'warning' },
  'no-access': { label: 'Sem acesso', tone: 'neutral' },
  loading: { label: 'Verificando…', tone: 'neutral' },
}

/**
 * "Primeiros passos": o percurso de trabalho do cadastro à aprovação do orçamento, com o que a organização
 * já fez (consultas à API; nada é gravado aqui) e atalhos para cada tela. Respeita as permissões do usuário.
 */
export function GettingStartedPage() {
  const { can, me } = useAuth()

  const results = useQueries({
    queries: steps.map((s) => ({
      queryKey: ['getting-started', s.id],
      queryFn: () => http<{ meta: { total: number } }>(s.check.path, { query: { per_page: 1, ...s.check.query } }).then((r) => r.meta.total),
      enabled: can(s.viewPermission),
      staleTime: 30_000,
    })),
  })

  const totals = Object.fromEntries(steps.map((s, i) => [s.id, results[i].data]))
  // planilha: há orçamento enviado ou aprovado (a API não deixa enviar orçamento vazio)
  totals.sheet = sheetDone(totals.sheet, totals.approval)
  const states = Object.fromEntries(steps.map((s) => [s.id, stepState(can(s.viewPermission), totals[s.id])])) as Record<string, StepState>
  const current = nextStep(states)
  const required = steps.filter((s) => !s.optional && states[s.id] !== 'no-access')
  const done = required.filter((s) => states[s.id] === 'done').length

  return (
    <div>
      <PageHeader title="Primeiros passos" description={`Percurso de trabalho em ${me?.active_tenant?.name ?? 'sua organização'}: do cadastro à aprovação do orçamento.`} />

      <Panel className="mb-4 p-4">
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-sm">
            <strong>{done}</strong> de <strong>{required.length}</strong> passos obrigatórios concluídos
          </p>
          <div className="bg-muted h-2 min-w-40 flex-1 overflow-hidden rounded-full" role="progressbar" aria-valuemin={0} aria-valuemax={required.length} aria-valuenow={done}>
            <div className="bg-primary h-full transition-all" style={{ width: `${required.length ? (done / required.length) * 100 : 0}%` }} />
          </div>
        </div>
      </Panel>

      <ol className="flex flex-col gap-3">
        {steps.map((s, i) => {
          const state = states[s.id]
          const isCurrent = s.id === current
          const canDo = can(s.managePermission)
          const Icon = state === 'done' ? CheckCircle2 : state === 'no-access' ? Lock : state === 'loading' ? Loader2 : Circle
          return (
            <li key={s.id}>
              <Panel className={cn('flex flex-col gap-3 p-4 sm:flex-row sm:items-start', isCurrent && 'border-primary ring-primary/20 ring-2')}>
                <div className="flex items-center gap-3 sm:w-8 sm:flex-col">
                  <Icon
                    className={cn('size-6 shrink-0', state === 'done' ? 'text-success-strong' : isCurrent ? 'text-primary' : 'text-muted-foreground', state === 'loading' && 'animate-spin')}
                    aria-hidden
                  />
                  <span className="text-muted-foreground text-xs sm:hidden">Passo {i + 1}</span>
                </div>
                <div className="flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold">
                      {i + 1}. {s.title}
                    </h2>
                    <StatusBadge tone={isCurrent ? 'primary' : badge[state].tone}>{isCurrent ? 'Você está aqui' : badge[state].label}</StatusBadge>
                    {s.optional && <span className="text-muted-foreground text-xs">opcional</span>}
                  </div>
                  <p className="text-muted-foreground mt-1 text-sm">{s.description}</p>
                  {s.tip && (
                    <p className="mt-2 flex items-start gap-1.5 text-xs">
                      <Lightbulb className="text-warning-strong mt-0.5 size-3.5 shrink-0" aria-hidden /> {s.tip}
                    </p>
                  )}
                  {state !== 'no-access' && !canDo && (
                    <p className="text-muted-foreground mt-2 text-xs">Seu perfil pode consultar, mas não fazer este passo — peça a quem administra.</p>
                  )}
                </div>
                {state !== 'no-access' && (
                  <Link to={s.to} className={buttonVariants({ variant: isCurrent ? 'default' : 'outline', size: 'lg' })}>
                    {s.action} <ArrowRight className="size-4" />
                  </Link>
                )}
              </Panel>
            </li>
          )
        })}
      </ol>

      <Alert tone="info" className="mt-4">
        Quem faz o quê: <strong>admin</strong> faz tudo (inclusive aprovar); <strong>member</strong> só consulta. Aprovar é uma permissão separada — quem aprova não precisa poder editar.
      </Alert>
    </div>
  )
}
