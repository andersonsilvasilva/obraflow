import { AlertCircle, Inbox, Loader2, Wrench } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/api-error'

export function LoadingState({ label = 'Carregando…' }: { label?: string }) {
  return (
    <div role="status" className="text-muted-foreground flex items-center justify-center gap-2 py-16 text-sm">
      <Loader2 className="size-5 animate-spin" aria-hidden />
      {label}
    </div>
  )
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <div className="bg-primary-soft text-primary rounded-full p-3">
        <Inbox className="size-6" aria-hidden />
      </div>
      <h3 className="text-base font-semibold">{title}</h3>
      {description && <p className="text-muted-foreground max-w-sm text-sm">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <div role="alert" className="flex flex-col items-center gap-2 py-16 text-center">
      <div className="bg-destructive-soft text-destructive rounded-full p-3">
        <AlertCircle className="size-6" aria-hidden />
      </div>
      <h3 className="text-base font-semibold">Não foi possível carregar</h3>
      <p className="text-muted-foreground max-w-sm text-sm">{getErrorMessage(error)}</p>
      {onRetry && (
        <Button variant="outline" onClick={onRetry} className="mt-2">
          Tentar novamente
        </Button>
      )}
    </div>
  )
}

/** Estado de módulo ainda não desenvolvido — não simula funcionalidade. */
export function ModuleInPreparation({ module, description }: { module: string; description?: string }) {
  return (
    <div className="bg-card flex flex-col items-center gap-3 rounded-xl border border-dashed py-20 text-center">
      <div className="bg-primary-soft text-primary rounded-full p-3">
        <Wrench className="size-6" aria-hidden />
      </div>
      <h2 className="text-lg font-semibold">{module} — em preparação</h2>
      <p className="text-muted-foreground max-w-md text-sm">
        {description ?? 'Este módulo ainda não foi desenvolvido. Ele será disponibilizado nas próximas etapas do roadmap.'}
      </p>
    </div>
  )
}
