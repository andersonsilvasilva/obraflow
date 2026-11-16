import { Building2, Check, ChevronDown, ChevronRight, ChevronsLeft, ChevronsRight, LogOut, Loader2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { filterNavigation, firstPath, navigation, type NavNode } from '@/app/navigation'
import { useToast } from '@/app/providers/ToastProvider'
import { useAuth } from '@/features/auth/auth-context'
import { getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'

interface SidebarProps {
  collapsed: boolean
  onToggle: () => void
  /** Em telas pequenas o menu vira gaveta; chamado ao navegar. */
  onNavigate?: () => void
}

const OPEN_KEY = 'obraflow:nav-groups'

/** Escolhas manuais de abrir/fechar grupos (chave do grupo → aberto). */
function readToggled(): Record<string, boolean> {
  try {
    return JSON.parse(localStorage.getItem(OPEN_KEY) ?? '{}') as Record<string, boolean>
  } catch {
    return {}
  }
}

function containsPath(node: NavNode, pathname: string): boolean {
  if (node.path && (node.path === '/' ? pathname === '/' : pathname.startsWith(node.path))) return true
  return node.children?.some((c) => containsPath(c, pathname)) ?? false
}

/** Menu lateral no padrão G2/G3: organização no topo, grupos em até 3 níveis, cartão do usuário no rodapé. */
export function Sidebar({ collapsed, onToggle, onNavigate }: SidebarProps) {
  const { can } = useAuth()
  const { pathname } = useLocation()
  const items = useMemo(() => filterNavigation(navigation, can), [can])
  const [toggled, setToggled] = useState<Record<string, boolean>>(readToggled)

  // grupos que contêm o item ativo abrem por padrão; a escolha manual do usuário prevalece
  const open = useMemo(() => {
    const result: string[] = []
    const walk = (nodes: NavNode[], trail: string) =>
      nodes.forEach((n) => {
        if (!n.children) return
        const key = trail ? `${trail}/${n.label}` : n.label
        if (toggled[key] ?? containsPath(n, pathname)) result.push(key)
        walk(n.children, key)
      })
    walk(items, '')
    return result
  }, [items, pathname, toggled])

  const toggleGroup = (key: string) =>
    setToggled((t) => {
      const next = { ...t, [key]: !open.includes(key) }
      try {
        localStorage.setItem(OPEN_KEY, JSON.stringify(next))
      } catch {
        // preferência não persistida
      }
      return next
    })

  return (
    <nav aria-label="Navegação principal" className="bg-sidebar text-sidebar-foreground flex h-full flex-col">
      <OrganizationSwitcher collapsed={collapsed} onToggle={onToggle} />

      <div className="flex-1 overflow-y-auto px-2 py-3">
        <ul className="flex flex-col gap-0.5">
          {items.map((node) => (
            <NavEntry key={node.label} node={node} depth={0} trail="" collapsed={collapsed} open={open} onToggleGroup={toggleGroup} onNavigate={onNavigate} />
          ))}
        </ul>
      </div>

      <UserCard collapsed={collapsed} />
    </nav>
  )
}

function NavEntry({
  node,
  depth,
  trail,
  collapsed,
  open,
  onToggleGroup,
  onNavigate,
}: {
  node: NavNode
  depth: number
  trail: string
  collapsed: boolean
  open: string[]
  onToggleGroup: (key: string) => void
  onNavigate?: () => void
}) {
  const key = trail ? `${trail}/${node.label}` : node.label
  const Icon = node.icon
  const indent = collapsed ? undefined : { paddingLeft: `${0.75 + depth * 1}rem` }
  const base = 'flex w-full items-center gap-3 rounded-lg py-2 pr-3 text-sm transition-colors'

  // menu recolhido: só o primeiro nível, como atalho para o primeiro item do grupo
  if (collapsed) {
    const to = firstPath(node)
    if (!to || depth > 0) return null
    return (
      <li>
        <NavLink
          to={to}
          end={to === '/'}
          onClick={onNavigate}
          title={node.label}
          className={({ isActive }) => cn(base, 'justify-center px-0', isActive ? 'bg-sidebar-primary text-sidebar-primary-foreground' : 'hover:bg-sidebar-accent')}
        >
          {Icon ? <Icon className="size-[18px] shrink-0" aria-hidden /> : <span aria-hidden>•</span>}
          <span className="sr-only">{node.label}</span>
        </NavLink>
      </li>
    )
  }

  if (node.children) {
    const isOpen = open.includes(key)
    return (
      <li>
        <button
          type="button"
          aria-expanded={isOpen}
          onClick={() => onToggleGroup(key)}
          style={indent}
          className={cn(base, 'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground', isOpen && depth === 0 && 'text-sidebar-accent-foreground')}
        >
          {Icon && <Icon className="size-[18px] shrink-0" aria-hidden />}
          <span className="flex-1 truncate text-left">{node.label}</span>
          {isOpen ? <ChevronDown className="size-4" aria-hidden /> : <ChevronRight className="size-4" aria-hidden />}
        </button>
        {isOpen && (
          <ul className="mt-0.5 flex flex-col gap-0.5">
            {node.children.map((child) => (
              <NavEntry key={child.label} node={child} depth={depth + 1} trail={key} collapsed={collapsed} open={open} onToggleGroup={onToggleGroup} onNavigate={onNavigate} />
            ))}
          </ul>
        )}
      </li>
    )
  }

  return (
    <li>
      <NavLink
        to={node.path ?? '/'}
        end={node.path === '/'}
        onClick={onNavigate}
        style={indent}
        className={({ isActive }) =>
          cn(base, isActive ? 'bg-sidebar-primary text-sidebar-primary-foreground font-medium' : 'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground')
        }
      >
        {Icon && <Icon className="size-[18px] shrink-0" aria-hidden />}
        <span className="flex-1 truncate">{node.label}</span>
        {node.demo && <span className="bg-sidebar-accent rounded px-1.5 py-0.5 text-[10px] tracking-wide uppercase">Demo</span>}
      </NavLink>
    </li>
  )
}

/** Topo do menu: organização ativa (troca quando houver mais de uma) + recolher. */
function OrganizationSwitcher({ collapsed, onToggle }: { collapsed: boolean; onToggle: () => void }) {
  const { me, selectTenant } = useAuth()
  const { notify } = useToast()
  const navigate = useNavigate()
  const [menu, setMenu] = useState(false)
  const [busy, setBusy] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const active = me?.active_tenant
  const others = (me?.tenants ?? []).filter((t) => t.uuid !== active?.uuid && t.status === 'active')

  useEffect(() => {
    if (!menu) return
    const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setMenu(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [menu])

  const switchTo = async (uuid: string) => {
    setBusy(true)
    try {
      await selectTenant(uuid)
      setMenu(false)
      navigate('/', { replace: true })
    } catch (e) {
      notify('error', 'Não foi possível trocar de organização', getErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div ref={ref} className="border-sidebar-border relative flex h-(--topbar-height) shrink-0 items-center gap-2 border-b px-3">
      {!collapsed && (
        <button
          type="button"
          disabled={others.length === 0}
          onClick={() => setMenu((m) => !m)}
          aria-haspopup="menu"
          aria-expanded={menu}
          className="hover:bg-sidebar-accent flex min-w-0 flex-1 items-center gap-2 rounded-lg p-1.5 text-left disabled:hover:bg-transparent"
        >
          <span className="bg-sidebar-primary text-sidebar-primary-foreground rounded-md p-1">
            <Building2 className="size-4" aria-hidden />
          </span>
          <span className="min-w-0 flex-1">
            <span className="text-sidebar-foreground/60 block text-[10px] tracking-wider uppercase">Organização</span>
            <span className="text-sidebar-accent-foreground block truncate text-sm font-semibold">{active?.name}</span>
          </span>
          {others.length > 0 && (busy ? <Loader2 className="size-4 animate-spin" /> : <ChevronDown className="size-4" aria-hidden />)}
        </button>
      )}
      <button
        type="button"
        onClick={onToggle}
        aria-label={collapsed ? 'Expandir menu' : 'Recolher menu'}
        className={cn('hover:bg-sidebar-accent rounded-lg p-1.5', collapsed && 'mx-auto')}
      >
        {collapsed ? <ChevronsRight className="size-4" /> : <ChevronsLeft className="size-4" />}
      </button>

      {menu && (
        <ul role="menu" className="bg-popover text-popover-foreground shadow-overlay absolute top-full right-2 left-2 z-20 mt-1 rounded-lg border p-1">
          <li role="none" className="text-muted-foreground flex items-center gap-2 px-2 py-1.5 text-sm">
            <Check className="size-4" aria-hidden /> {active?.name}
          </li>
          {others.map((t) => (
            <li role="none" key={t.uuid}>
              <button role="menuitem" type="button" disabled={busy} onClick={() => switchTo(t.uuid)} className="hover:bg-muted w-full rounded-md px-2 py-1.5 pl-8 text-left text-sm">
                {t.name}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts.at(-1)?.[0] ?? '') : '')).toUpperCase()
}

/** Rodapé do menu: cartão do usuário com "Sair". */
function UserCard({ collapsed }: { collapsed: boolean }) {
  const { me, logout } = useAuth()
  const navigate = useNavigate()
  if (!me) return null

  const exit = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <div className="border-sidebar-border flex shrink-0 items-center gap-2 border-t p-3">
      <span
        className="bg-sidebar-primary text-sidebar-primary-foreground flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
        title={collapsed ? me.user.name : undefined}
        aria-hidden
      >
        {initials(me.user.name)}
      </span>
      {!collapsed && (
        <span className="min-w-0 flex-1">
          <span className="text-sidebar-accent-foreground block truncate text-sm font-medium">{me.user.name}</span>
          <span className="text-sidebar-foreground/70 block truncate text-xs">{me.user.email}</span>
        </span>
      )}
      <button type="button" onClick={exit} aria-label="Sair" title="Sair" className={cn('hover:bg-sidebar-accent rounded-lg p-1.5', collapsed && 'hidden')}>
        <LogOut className="size-4" />
      </button>
    </div>
  )
}
