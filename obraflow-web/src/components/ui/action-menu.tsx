import { Menu } from '@base-ui/react/menu'
import { EllipsisVertical, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from './button'

export interface ActionMenuItem {
  label: string
  icon?: LucideIcon
  onClick?: () => void
  disabled?: boolean
  /** Texto curto ao lado do item desabilitado (ex.: "em breve"). */
  badge?: string
  destructive?: boolean
}

/**
 * Menu de ações "⋮" (Base UI: teclado, foco e leitor de tela). Itens desabilitados continuam visíveis
 * (padrão C8: ação indisponível aparece desabilitada, não some).
 */
export function ActionMenu({ label, items }: { label: string; items: ActionMenuItem[] }) {
  return (
    <Menu.Root>
      <Menu.Trigger render={<Button variant="outline" size="icon-lg" aria-label={label} title={label} />}>
        <EllipsisVertical className="size-4" />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Positioner sideOffset={4} align="end" className="z-50">
          <Menu.Popup className="bg-popover text-popover-foreground min-w-56 rounded-lg border p-1 shadow-(--shadow-overlay) outline-none">
            {items.map((item) => {
              const Icon = item.icon
              return (
                <Menu.Item
                  key={item.label}
                  disabled={item.disabled}
                  onClick={item.onClick}
                  className={cn(
                    'flex cursor-default items-center gap-2 rounded-md px-2.5 py-2 text-sm outline-none select-none',
                    'data-highlighted:bg-primary-soft data-disabled:text-muted-foreground data-disabled:cursor-not-allowed',
                    item.destructive && 'text-destructive',
                  )}
                >
                  {Icon && <Icon className="size-4 shrink-0" aria-hidden />}
                  <span className="flex-1">{item.label}</span>
                  {item.badge && <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[0.7rem]">{item.badge}</span>}
                </Menu.Item>
              )
            })}
          </Menu.Popup>
        </Menu.Positioner>
      </Menu.Portal>
    </Menu.Root>
  )
}
