import { ChevronRight } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'
import { routeLabels } from '@/app/navigation'

export function Breadcrumb() {
  const { pathname } = useLocation()
  const segments = pathname.split('/').filter(Boolean)

  const crumbs = segments.map((seg, i) => ({
    to: `/${segments.slice(0, i + 1).join('/')}`,
    label: routeLabels[seg] ?? seg.toUpperCase(),
  }))

  return (
    <nav aria-label="Trilha de navegação" className="text-muted-foreground mb-2 text-xs">
      <ol className="flex flex-wrap items-center gap-1">
        <li>
          <Link to="/" className="hover:text-primary">Início</Link>
        </li>
        {crumbs.map((c, i) => (
          <li key={c.to} className="flex items-center gap-1">
            <ChevronRight className="size-3" aria-hidden />
            {i === crumbs.length - 1 ? (
              <span aria-current="page" className="text-foreground font-medium">{c.label}</span>
            ) : (
              <Link to={c.to} className="hover:text-primary">{c.label}</Link>
            )}
          </li>
        ))}
      </ol>
    </nav>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-5">
      <Breadcrumb />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description && <p className="text-muted-foreground mt-0.5 text-sm">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}
