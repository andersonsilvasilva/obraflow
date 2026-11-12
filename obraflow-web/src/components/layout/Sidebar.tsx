import { ChevronsLeft, ChevronsRight, HardHat } from 'lucide-react'
import { NavLink } from 'react-router-dom'
import { navigation } from '@/app/navigation'
import { cn } from '@/lib/utils'

interface SidebarProps {
  collapsed: boolean
  onToggle: () => void
  /** Em telas pequenas o menu vira gaveta; chamado ao navegar. */
  onNavigate?: () => void
}

export function Sidebar({ collapsed, onToggle, onNavigate }: SidebarProps) {
  return (
    <nav aria-label="Navegação principal" className="bg-sidebar text-sidebar-foreground flex h-full flex-col">
      <div className="border-sidebar-border flex h-(--topbar-height) shrink-0 items-center gap-2 border-b px-4">
        <span className="bg-sidebar-primary text-sidebar-primary-foreground rounded-lg p-1.5">
          <HardHat className="size-5" aria-hidden />
        </span>
        {!collapsed && <span className="text-sidebar-accent-foreground text-lg font-semibold tracking-tight">ObraFlow</span>}
      </div>

      <div className="flex-1 overflow-y-auto px-2 py-3">
        {navigation.map((group) => (
          <div key={group.label} className="mb-4">
            {collapsed ? (
              <hr className="border-sidebar-border mx-2 mb-2" />
            ) : (
              <p className="text-sidebar-foreground/60 px-3 pb-1 text-[11px] font-semibold tracking-wider uppercase">{group.label}</p>
            )}
            <ul className="flex flex-col gap-0.5">
              {group.items.map((item) => (
                <li key={item.path}>
                  <NavLink
                    to={item.path}
                    end={item.path === '/'}
                    onClick={onNavigate}
                    title={collapsed ? item.label : undefined}
                    className={({ isActive }) =>
                      cn(
                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                        collapsed && 'justify-center px-0',
                        isActive
                          ? 'bg-sidebar-primary text-sidebar-primary-foreground font-medium'
                          : 'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                      )
                    }
                  >
                    <item.icon className="size-[18px] shrink-0" aria-hidden />
                    {collapsed ? <span className="sr-only">{item.label}</span> : <span className="truncate">{item.label}</span>}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={onToggle}
        aria-label={collapsed ? 'Expandir menu' : 'Recolher menu'}
        className="border-sidebar-border hover:bg-sidebar-accent hidden h-11 shrink-0 items-center justify-center gap-2 border-t text-sm lg:flex"
      >
        {collapsed ? <ChevronsRight className="size-4" /> : <><ChevronsLeft className="size-4" /> Recolher</>}
      </button>
    </nav>
  )
}
