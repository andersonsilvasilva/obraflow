const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
const compact = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
  notation: 'compact',
  maximumFractionDigits: 1,
})
const decimal = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
const percent = new Intl.NumberFormat('pt-BR', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 })
const dateFmt = new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' })

export const formatCurrency = (value: number) => currency.format(value)
export const formatCompactCurrency = (value: number) => compact.format(value)
export const formatDecimal = (value: number) => decimal.format(value)
/** value em fração (0.25 = 25%) */
export const formatPercent = (value: number) => percent.format(value)
/** Recebe ISO (YYYY-MM-DD) e formata sem deslocamento de fuso. */
export const formatDate = (iso: string) => dateFmt.format(new Date(`${iso}T00:00:00Z`))

/** CPF (11) ou CNPJ (14) a partir de dígitos; máscaras já aplicadas pela API (ex.: ***.982.247-**) passam intactas. */
export function formatDocument(value: string | null | undefined): string {
  if (!value) return '—'
  if (!/^\d+$/.test(value)) return value
  if (value.length === 11) return value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  if (value.length === 14) return value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
  return value
}

/** Data ISO (YYYY-MM-DD) ou vazio → texto. */
export const formatDateOrDash = (iso: string | null | undefined) => (iso ? formatDate(iso.slice(0, 10)) : '—')
