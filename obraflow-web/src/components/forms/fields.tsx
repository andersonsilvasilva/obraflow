import { Search } from 'lucide-react'
import { cloneElement, useId, type InputHTMLAttributes, type ReactElement, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

const controlCls =
  'border-input bg-card placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/30 h-9 w-full rounded-lg border px-3 text-sm outline-none focus-visible:ring-3 disabled:opacity-50 aria-invalid:border-destructive'

export function TextInput({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={cn(controlCls, className)} {...props} />
}

export function SelectInput({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className={cn(controlCls, className)} {...props}>
      {children}
    </select>
  )
}

export function TextArea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={cn(controlCls, 'h-auto min-h-20 py-2', className)} {...props} />
}

interface FormFieldProps {
  label: string
  error?: string
  hint?: string
  required?: boolean
  className?: string
  /** O controle deve aceitar id, aria-invalid e aria-describedby. */
  children: ReactElement<Record<string, unknown>>
}

/** Associa label, controle, dica e erro com os atributos ARIA corretos. */
export function FormField({ label, error, hint, required, className, children }: FormFieldProps) {
  const id = useId()
  const descId = `${id}-desc`
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-sm font-medium">
        {label}
        {required && <span className="text-destructive ml-0.5" aria-hidden>*</span>}
      </label>
      {cloneElement(children, {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error || hint ? descId : undefined,
      })}
      {(error || hint) && (
        <p id={descId} className={cn('text-xs', error ? 'text-destructive' : 'text-muted-foreground')}>
          {error ?? hint}
        </p>
      )}
    </div>
  )
}

export function SearchInput({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className={cn('relative', className)}>
      <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
      <input type="search" className={cn(controlCls, 'pl-9')} {...props} />
    </div>
  )
}

interface CurrencyInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'> {
  value: number
  onValueChange: (value: number) => void
}

/** Entrada monetária em R$ (centavos digitados da direita para a esquerda). */
export function CurrencyInput({ value, onValueChange, className, ...props }: CurrencyInputProps) {
  const display = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
  return (
    <div className="relative">
      <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm">R$</span>
      <input
        inputMode="numeric"
        className={cn(controlCls, 'pl-9 text-right tabular-nums', className)}
        value={display}
        onChange={(e) => {
          const digits = e.target.value.replace(/\D/g, '')
          onValueChange(Number(digits || '0') / 100)
        }}
        {...props}
      />
    </div>
  )
}
