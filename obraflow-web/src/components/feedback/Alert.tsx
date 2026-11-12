import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

type AlertTone = 'info' | 'success' | 'warning' | 'danger'

// Cores de texto escolhidas para contraste AA sobre o fundo suave (ver DESIGN_SYSTEM.md).
const tones: Record<AlertTone, { icon: typeof Info; cls: string }> = {
  info: { icon: Info, cls: 'bg-primary-soft text-primary-deep' },
  success: { icon: CircleCheck, cls: 'bg-success-soft text-success-strong' },
  warning: { icon: TriangleAlert, cls: 'bg-warning-soft text-warning-strong' },
  danger: { icon: CircleAlert, cls: 'bg-destructive-soft text-destructive' },
}

/** Aviso em linha. `danger` usa role="alert"; os demais, role="note". */
export function Alert({ tone = 'info', title, children, className }: { tone?: AlertTone; title?: string; children: ReactNode; className?: string }) {
  const { icon: Icon, cls } = tones[tone]
  return (
    <div role={tone === 'danger' ? 'alert' : 'note'} className={cn('flex items-start gap-2 rounded-lg px-3 py-2 text-sm', cls, className)}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div>
        {title && <p className="font-semibold">{title}</p>}
        <div>{children}</div>
      </div>
    </div>
  )
}
