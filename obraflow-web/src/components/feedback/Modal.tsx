import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  children: ReactNode
  footer?: ReactNode
  /** "side" abre como painel lateral */
  variant?: 'center' | 'side'
  size?: 'md' | 'lg' | 'xl'
}

const sizes = { md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

/** Modal acessível baseado em <dialog> nativo (foco preso, ESC fecha). */
export function Modal({ open, onClose, title, description, children, footer, variant = 'center', size = 'lg' }: ModalProps) {
  const ref = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onClose={onClose}
      onClick={(e) => e.target === ref.current && onClose()}
      className={cn(
        'bg-card text-card-foreground shadow-overlay fixed m-0 w-full p-0 backdrop:bg-black/40',
        variant === 'center' && cn('top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rounded-xl', sizes[size]),
        variant === 'side' && 'top-0 right-0 left-auto h-dvh max-h-dvh max-w-xl rounded-l-xl',
      )}
    >
      {open && (
        <div className={cn('flex flex-col', variant === 'side' ? 'h-dvh' : 'max-h-[85dvh]')}>
          <header className="flex items-start justify-between gap-4 border-b p-4">
            <div>
              <h2 id={titleId} className="text-lg font-semibold">
                {title}
              </h2>
              {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            <Button variant="ghost" size="icon" onClick={onClose} aria-label="Fechar">
              <X className="size-4" />
            </Button>
          </header>
          <div className="flex-1 overflow-y-auto p-4">{children}</div>
          {footer && <footer className="flex justify-end gap-2 border-t p-4">{footer}</footer>}
        </div>
      )}
    </dialog>
  )
}

interface ConfirmDialogProps {
  open: boolean
  title: string
  description: string
  confirmLabel?: string
  destructive?: boolean
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = 'Confirmar',
  destructive,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onCancel}>
            Cancelar
          </Button>
          <Button variant={destructive ? 'destructive' : 'default'} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="text-sm">{description}</p>
    </Modal>
  )
}
