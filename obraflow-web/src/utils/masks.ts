/**
 * Máscaras de entrada (CPF, CNPJ, telefone fixo/celular, CEP) e validação na tela.
 * A máscara é só apresentação: a API grava documentos e CEP SÓ COM DÍGITOS e confere tudo de novo (é a autoridade).
 */

export type MaskKind = 'cpf' | 'cnpj' | 'cpf_cnpj' | 'phone' | 'cep'

const digits = (v: string) => v.replace(/\D/g, '')

/** Aplica um padrão com "0" no lugar de cada dígito, até onde houver dígitos (máscara progressiva). */
function pattern(value: string, mask: string): string {
  const d = digits(value)
  let out = ''
  let i = 0
  for (const ch of mask) {
    if (i >= d.length) break
    if (ch === '0') out += d[i++]
    else out += ch
  }
  return out
}

export const maskCpf = (v: string) => pattern(v, '000.000.000-00')
export const maskCnpj = (v: string) => pattern(v, '00.000.000/0000-00')
export const maskCep = (v: string) => pattern(v, '00000-000')

/** Até 11 dígitos é CPF; mais que isso, CNPJ (quando o tipo de pessoa não é conhecido). */
export const maskCpfCnpj = (v: string) => (digits(v).length <= 11 ? maskCpf(v) : maskCnpj(v))

/** Fixo (00) 0000-0000 com 10 dígitos; celular (00) 00000-0000 com 11. */
export function maskPhone(v: string): string {
  const d = digits(v).slice(0, 11)
  return d.length <= 10 ? pattern(d, '(00) 0000-0000') : pattern(d, '(00) 00000-0000')
}

/**
 * Aplica a máscara do campo. Valores que já chegam mascarados pela API (ex.: CPF "***.982.247-**", D-P21)
 * ficam como estão — nunca são "remascarados" nem enviados de volta.
 * `personType` (person/company) define CPF ou CNPJ em campos de documento de cliente/fornecedor.
 */
export function applyMask(kind: MaskKind, value: string, personType?: string): string {
  if (value.includes('*')) return value
  switch (kind) {
    case 'cpf':
      return maskCpf(value)
    case 'cnpj':
      return maskCnpj(value)
    case 'cpf_cnpj':
      return personType === 'person' ? maskCpf(value) : personType === 'company' ? maskCnpj(value) : maskCpfCnpj(value)
    case 'phone':
      return maskPhone(value)
    case 'cep':
      return maskCep(value)
  }
}

export function isCpf(value: string): boolean {
  const d = digits(value)
  if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false
  for (const t of [9, 10]) {
    let sum = 0
    for (let i = 0; i < t; i++) sum += Number(d[i]) * (t + 1 - i)
    if (Number(d[t]) !== ((sum * 10) % 11) % 10) return false
  }
  return true
}

export function isCnpj(value: string): boolean {
  const d = digits(value)
  if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) return false
  const calc = (len: number) => {
    const weights = len === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
    const sum = weights.reduce((acc, w, i) => acc + Number(d[i]) * w, 0)
    const r = sum % 11
    return r < 2 ? 0 : 11 - r
  }
  return calc(12) === Number(d[12]) && calc(13) === Number(d[13])
}

/** Mensagem de erro para o campo, ou `true` se válido (vazio é válido: obrigatoriedade é outra regra). */
export function validateMask(kind: MaskKind, value: string, personType?: string): true | string {
  const v = (value ?? '').trim()
  if (v === '' || v.includes('*')) return true
  const d = digits(v)
  switch (kind) {
    case 'cpf':
      return isCpf(d) || 'CPF inválido.'
    case 'cnpj':
      return isCnpj(d) || 'CNPJ inválido.'
    case 'cpf_cnpj':
      if (personType === 'person') return isCpf(d) || 'CPF inválido.'
      if (personType === 'company') return isCnpj(d) || 'CNPJ inválido.'
      return isCpf(d) || isCnpj(d) || 'CPF ou CNPJ inválido.'
    case 'phone':
      return d.length === 10 || d.length === 11 || 'Telefone deve ter DDD + 8 ou 9 dígitos.'
    case 'cep':
      return d.length === 8 || 'CEP deve ter 8 dígitos.'
  }
}

export const maskPlaceholder: Record<MaskKind, string> = {
  cpf: '000.000.000-00',
  cnpj: '00.000.000/0000-00',
  cpf_cnpj: 'CPF ou CNPJ',
  phone: '(00) 00000-0000',
  cep: '00000-000',
}

/** Formatação para exibição em listas (telefone gravado com ou sem máscara). */
export const formatPhone = (v: string | null | undefined) => (v ? maskPhone(v) || v : '—')
