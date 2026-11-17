import { ChevronDown, ChevronRight, CircleHelp, Loader2, Settings2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert } from '@/components/feedback/Alert'
import { Modal } from '@/components/feedback/Modal'
import { FormField, SelectInput, TextArea, TextInput } from '@/components/forms/fields'
import { Button } from '@/components/ui/button'
import { ApiError, getErrorMessage } from '@/lib/api-error'
import { cn } from '@/lib/utils'
import { applyMask, maskPlaceholder, validateMask, type MaskKind } from '@/utils/masks'
import type { Option } from '../api'
import { CatalogQuickModal, type QuickAddConfig } from './CatalogQuickModal'

export interface FieldDef {
  name: string
  label: string
  type?: 'text' | 'email' | 'tel' | 'date' | 'number' | 'textarea' | 'select' | 'radio'
  /** Opções fixas ou chave de `lookups` */
  options?: Option[] | string
  required?: boolean
  /** Só na criação (ex.: empresa); na edição aparece desabilitado */
  createOnly?: boolean
  placeholder?: string
  /** Texto do ícone (?) ao lado do rótulo — F2 */
  help?: string
  /** Ocupa as duas colunas */
  full?: boolean
  /** Fica no bloco recolhível "Mais informações" — F5 */
  more?: boolean
  /** Cadastro rápido da entidade auxiliar sem sair do formulário — F4 */
  quickAdd?: QuickAddConfig
  step?: string
  /** Visível conforme os valores atuais (ex.: campo só para fornecedor pessoa jurídica) */
  visible?: (values: Record<string, string>) => boolean
  /** Máscara de entrada + validação na tela (CPF, CNPJ, CPF/CNPJ conforme o tipo, telefone, CEP). */
  mask?: MaskKind
}

/** Campo que define pessoa física/jurídica para a máscara `cpf_cnpj`. */
const PERSON_TYPE_FIELD = 'type'

interface Props {
  title: string
  fields: FieldDef[]
  lookups: Record<string, Option[]>
  mode: 'create' | 'edit'
  initial: Record<string, string>
  size?: 'md' | 'lg' | 'xl'
  onClose: () => void
  onSubmit: (body: Record<string, unknown>) => Promise<unknown>
  onSaved: () => void
}

/**
 * Modal de formulário (F1): 2 colunas com rótulo acima, obrigatórios com asterisco, Cancelar + ação principal.
 * Validação de forma no navegador (obrigatórios); a API é a autoridade — erros 422 voltam para cada campo
 * e regras de negócio (domain_rule, duplicidade) aparecem no topo.
 */
export function EntityFormModal({ title, fields, lookups, mode, initial, size = 'lg', onClose, onSubmit, onSaved }: Props) {
  const defaults = Object.fromEntries(
    fields.map((f) => {
      const raw = initial[f.name] ?? (f.type === 'radio' ? (optionsFor(f, lookups)[0]?.value ?? '') : '')
      return [f.name, f.mask && raw ? applyMask(f.mask, raw, initial[PERSON_TYPE_FIELD]) : raw]
    }),
  )
  const { register, handleSubmit, setError, watch, setValue, getValues, formState } = useForm<Record<string, string>>({ defaultValues: defaults })
  const [topError, setTopError] = useState<string | null>(null)
  const [showMore, setShowMore] = useState(fields.some((f) => f.more && initial[f.name]))
  const [quick, setQuick] = useState<FieldDef | null>(null)
  const values = watch()
  const personType = values[PERSON_TYPE_FIELD]

  // trocar pessoa física/jurídica reaplica a máscara do documento
  useEffect(() => {
    for (const f of fields) {
      if (f.mask === 'cpf_cnpj') {
        const cur = getValues(f.name) ?? ''
        if (cur) setValue(f.name, applyMask('cpf_cnpj', cur, personType))
      }
    }
  }, [personType, fields, getValues, setValue])

  const visible = fields.filter((f) => !f.visible || f.visible(values))

  const submit = handleSubmit(async (form) => {
    setTopError(null)
    const body: Record<string, unknown> = {}
    for (const f of visible) {
      if (mode === 'edit' && f.createOnly) continue
      const v = (form[f.name] ?? '').toString().trim()
      body[f.name] = v === '' ? null : v
    }
    try {
      await onSubmit(body)
      onSaved()
    } catch (e) {
      if (e instanceof ApiError && e.details) {
        let mapped = false
        for (const [field, messages] of Object.entries(e.details)) {
          if (visible.some((f) => f.name === field)) {
            setError(field, { message: messages[0] })
            mapped = true
          }
        }
        if (!mapped) setTopError(Object.values(e.details).flat()[0] ?? e.message)
      } else {
        setTopError(getErrorMessage(e))
      }
    }
  })

  const main = visible.filter((f) => !f.more)
  const more = visible.filter((f) => f.more)

  const renderField = (f: FieldDef) => {
    const disabled = mode === 'edit' && f.createOnly
    const error = formState.errors[f.name]?.message
    const reg = register(f.name, {
      required: f.required && !disabled ? 'Campo obrigatório.' : false,
      validate: f.mask && !disabled ? (v: string) => validateMask(f.mask!, v, getValues(PERSON_TYPE_FIELD)) : undefined,
    })
    const opts = optionsFor(f, lookups)
    const label = f.help ? `${f.label}` : f.label

    let control
    if (f.type === 'radio') {
      return (
        <fieldset key={f.name} className={cn('flex flex-col gap-1.5', f.full && 'sm:col-span-2')}>
          <legend className="mb-1.5 text-sm font-medium">
            {f.label}
            {f.required && <span className="text-destructive ml-0.5" aria-hidden>*</span>}
          </legend>
          <div className="flex flex-wrap gap-4">
            {opts.map((o) => (
              <label key={o.value} className="flex items-center gap-2 text-sm">
                <input type="radio" value={o.value} disabled={disabled} className="accent-(--primary) size-4" {...reg} />
                {o.label}
              </label>
            ))}
          </div>
          {error && <p className="text-destructive text-xs">{error}</p>}
        </fieldset>
      )
    }
    if (f.type === 'select') {
      control = (
        <SelectInput disabled={disabled} {...reg}>
          <option value="">{f.placeholder ?? 'Selecione…'}</option>
          {opts.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </SelectInput>
      )
    } else if (f.type === 'textarea') {
      control = <TextArea disabled={disabled} placeholder={f.placeholder} {...reg} />
    } else {
      control = f.mask ? (
        <TextInput
          type="text"
          inputMode={f.mask === 'phone' ? 'tel' : 'numeric'}
          autoComplete={f.mask === 'phone' ? 'tel' : f.mask === 'cep' ? 'postal-code' : 'off'}
          disabled={disabled}
          placeholder={f.placeholder ?? (f.mask === 'cpf_cnpj' && personType ? maskPlaceholder[personType === 'person' ? 'cpf' : 'cnpj'] : maskPlaceholder[f.mask])}
          {...reg}
          onChange={(e) => {
            e.target.value = applyMask(f.mask!, e.target.value, getValues(PERSON_TYPE_FIELD))
            return reg.onChange(e)
          }}
        />
      ) : (
        <TextInput type={f.type ?? 'text'} step={f.step} disabled={disabled} placeholder={f.placeholder} {...reg} />
      )
    }

    return (
      <div key={f.name} className={cn('relative', f.full && 'sm:col-span-2')}>
        <FormField label={label} required={f.required} error={error}>
          {f.quickAdd ? (
            <div className="flex gap-2">
              {control}
              <Button type="button" variant="outline" size="icon-lg" aria-label={`Gerenciar ${f.label}`} title={`Gerenciar ${f.label}`} onClick={() => setQuick(f)}>
                <Settings2 className="size-4" />
              </Button>
            </div>
          ) : (
            control
          )}
        </FormField>
        {f.help && (
          <span className="text-muted-foreground absolute top-0 right-0" title={f.help}>
            <CircleHelp className="size-4" aria-label={f.help} />
          </span>
        )}
      </div>
    )
  }

  return (
    <>
      <Modal
        open
        onClose={onClose}
        title={title}
        size={size}
        footer={
          <>
            <Button variant="outline" size="lg" onClick={onClose}>
              Cancelar
            </Button>
            <Button size="lg" onClick={submit} disabled={formState.isSubmitting}>
              {formState.isSubmitting && <Loader2 className="size-4 animate-spin" aria-hidden />}
              {mode === 'create' ? 'Criar' : 'Atualizar'}
            </Button>
          </>
        }
      >
        <form onSubmit={submit} noValidate>
          {topError && (
            <Alert tone="danger" className="mb-4">
              {topError}
            </Alert>
          )}
          <div className="grid gap-4 sm:grid-cols-2">{main.map(renderField)}</div>
          {more.length > 0 && (
            <div className="mt-4">
              <button type="button" aria-expanded={showMore} onClick={() => setShowMore((s) => !s)} className="text-primary flex items-center gap-1 text-sm font-medium">
                {showMore ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />} Mais informações
              </button>
              {showMore && <div className="mt-3 grid gap-4 sm:grid-cols-2">{more.map(renderField)}</div>}
            </div>
          )}
          <button type="submit" hidden />
        </form>
      </Modal>

      {quick?.quickAdd && (
        <CatalogQuickModal
          config={quick.quickAdd}
          onClose={() => setQuick(null)}
          onPick={(id) => {
            setValue(quick.name, String(id), { shouldDirty: true })
            setQuick(null)
          }}
        />
      )}
    </>
  )
}

function optionsFor(f: FieldDef, lookups: Record<string, Option[]>): Option[] {
  if (!f.options) return []
  return typeof f.options === 'string' ? (lookups[f.options] ?? []) : f.options
}
