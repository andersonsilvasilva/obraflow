import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export type StatusTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger'

const toneClass: Record<StatusTone, string> = {
  neutral: 'bg-muted text-muted-foreground',
  primary: 'bg-primary-soft text-primary-deep',
  success: 'bg-success-soft text-success-strong',
  warning: 'bg-warning-soft text-warning-strong',
  danger: 'bg-destructive-soft text-destructive',
}

export function StatusBadge({ tone = 'neutral', children }: { tone?: StatusTone; children: ReactNode }) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', toneClass[tone])}>
      {children}
    </span>
  )
}

interface StatCardProps {
  label: string
  value: string
  icon?: LucideIcon
  hint?: string
  trend?: { value: string; positive: boolean }
}

export function StatCard({ label, value, icon: Icon, hint, trend }: StatCardProps) {
  return (
    <div className="bg-card shadow-card rounded-xl border p-4">
      <div className="flex items-start justify-between gap-2">
        <p className="text-muted-foreground text-sm">{label}</p>
        {Icon && (
          <span className="bg-primary-soft text-primary rounded-lg p-2">
            <Icon className="size-4" aria-hidden />
          </span>
        )}
      </div>
      <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
      <div className="mt-1 flex items-center gap-2 text-xs">
        {trend && (
          <span className={trend.positive ? 'text-success-strong font-medium' : 'text-destructive font-medium'}>{trend.value}</span>
        )}
        {hint && <span className="text-muted-foreground">{hint}</span>}
      </div>
    </div>
  )
}

/** Variante compacta usada em resumos (ex.: finalização de orçamento). */
export function MetricCard({ label, value, emphasis }: { label: string; value: string; emphasis?: boolean }) {
  return (
    <div className={cn('rounded-lg border p-3', emphasis ? 'bg-primary text-primary-foreground border-primary' : 'bg-card')}>
      <p className={cn('text-xs', emphasis ? 'text-primary-foreground/80' : 'text-muted-foreground')}>{label}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
    </div>
  )
}

export function ProgressIndicator({ value, label }: { value: number; label?: string }) {
  const pct = Math.max(0, Math.min(100, value))
  return (
    <div className="flex min-w-28 items-center gap-2">
      <div
        role="progressbar"
        aria-valuenow={pct}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label ?? 'Progresso'}
        className="bg-primary-soft h-2 flex-1 overflow-hidden rounded-full"
      >
        <div className="bg-primary h-full rounded-full" style={{ width: `${pct}%` }} />
      </div>
      <span className="text-muted-foreground w-9 text-right text-xs tabular-nums">{pct}%</span>
    </div>
  )
}

export function DemoBanner({ children }: { children?: ReactNode }) {
  return (
    <div className="bg-primary-soft text-primary-deep mb-4 rounded-lg px-3 py-2 text-sm" role="note">
      <strong>Dados de demonstração.</strong> {children ?? 'Os valores exibidos são fictícios e não representam informações reais ou oficiais.'}
    </div>
  )
}
