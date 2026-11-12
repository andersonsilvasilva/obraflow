import type { StatusTone } from '@/components/data-display/indicators'
import type { BudgetStatus, ProjectStatus, RequestPriority, RequestStatus } from '@/types'

type Meta = { label: string; tone: StatusTone }

export const projectStatusMeta: Record<ProjectStatus, Meta> = {
  planning: { label: 'Planejamento', tone: 'neutral' },
  in_progress: { label: 'Em andamento', tone: 'primary' },
  completed: { label: 'Concluída', tone: 'success' },
  delayed: { label: 'Em atraso', tone: 'danger' },
  paused: { label: 'Pausada', tone: 'warning' },
}

export const budgetStatusMeta: Record<BudgetStatus, Meta> = {
  draft: { label: 'Rascunho', tone: 'neutral' },
  in_review: { label: 'Em revisão', tone: 'warning' },
  approved: { label: 'Aprovado', tone: 'success' },
  rejected: { label: 'Rejeitado', tone: 'danger' },
}

export const priorityMeta: Record<RequestPriority, Meta> = {
  low: { label: 'Baixa', tone: 'neutral' },
  medium: { label: 'Média', tone: 'primary' },
  high: { label: 'Alta', tone: 'warning' },
  urgent: { label: 'Urgente', tone: 'danger' },
}

export const requestStatusMeta: Record<RequestStatus, Meta> = {
  draft: { label: 'Rascunho', tone: 'neutral' },
  pending: { label: 'Aguardando aprovação', tone: 'warning' },
  approved: { label: 'Aprovada', tone: 'success' },
  quoting: { label: 'Em cotação', tone: 'primary' },
  rejected: { label: 'Rejeitada', tone: 'danger' },
}
