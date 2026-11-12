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
