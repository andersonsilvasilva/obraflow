import type { FieldDef } from '@/features/registry/components/EntityFormModal'
import { useCompanyOptions, useCustomerOptions, useEmployeeOptions } from '@/features/registry/lookups'
import type { Project } from './api'

const UFS = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ').map((u) => ({ value: u, label: u }))

/** Encargo social da obra (A-47, D-P03) — mesmas opções do Brickup. */
export const regimeOptions = [
  { value: 'without_relief', label: 'Sem desoneração' },
  { value: 'with_relief', label: 'Com desoneração' },
  { value: 'not_applicable', label: 'Sem encargo' },
]

/** Campos do endereço do canteiro no formulário (A-47); vão para a API como `site_address`. */
const ADDRESS_FIELDS = ['postal_code', 'street', 'number', 'complement', 'district', 'city', 'state'] as const

export const projectFields: FieldDef[] = [
  { name: 'company_uuid', label: 'Empresa', type: 'select', options: 'companies', required: true, createOnly: true, full: true },
  {
    name: 'code',
    label: 'Código',
    placeholder: `Automático: OB-001/${new Date().getFullYear()}`,
    help: 'Deixe em branco para gerar automaticamente no formato OB-NNN/AAAA: numeração sequencial que recomeça a cada ano, usando o ano da data de início (sem data, o ano atual).',
  },
  { name: 'name', label: 'Nome da obra', required: true },
  // endereço do canteiro (CEP preenche logradouro, bairro, cidade e UF)
  { name: 'postal_code', label: 'CEP', mask: 'cep' },
  { name: 'street', label: 'Logradouro', placeholder: 'Rua, avenida, travessa…' },
  { name: 'number', label: 'Número' },
  { name: 'complement', label: 'Complemento', placeholder: 'Bloco, lote…' },
  { name: 'district', label: 'Bairro' },
  { name: 'city', label: 'Cidade' },
  { name: 'state', label: 'UF', type: 'select', options: UFS },
  { name: 'customer_uuid', label: 'Cliente', type: 'select', options: 'customers', help: 'Cliente da mesma empresa da obra.' },
  { name: 'technical_manager_uuid', label: 'Responsável técnico', type: 'select', options: 'employees', help: 'Funcionário da mesma empresa da obra.' },
  { name: 'foreman_uuid', label: 'Encarregado', type: 'select', options: 'employees', help: 'Funcionário da mesma empresa da obra.' },
  { name: 'start_date', label: 'Data inicial', type: 'date' },
  { name: 'duration_months', label: 'Prazo (meses)', type: 'number', step: '1', help: 'Com a data inicial, calcula a data de entrega quando ela fica em branco.' },
  { name: 'expected_end_date', label: 'Data de entrega', type: 'date' },
  { name: 'contract_value', label: 'Valor do contrato (R$)', type: 'number', step: '0.01', more: true },
  { name: 'built_area', label: 'Área a ser construída (m²)', type: 'number', step: '0.01', more: true },
  { name: 'social_charges_regime', label: 'Encargo social', type: 'select', options: regimeOptions, more: true, help: 'Padrão dos orçamentos da obra: a base SINAPI escolhida precisa ter o mesmo regime.' },
  { name: 'cno', label: 'CNO', mask: 'cno', more: true, help: 'Cadastro Nacional de Obras (Receita Federal), 12 dígitos.' },
  { name: 'progress', label: 'Progresso (%)', type: 'number', step: '1', more: true, help: 'Informado manualmente (D-P27).' },
  { name: 'actual_end_date', label: 'Término real', type: 'date', more: true },
  { name: 'description', label: 'Descrição', type: 'textarea', full: true, more: true },
]

export const useProjectLookups = () => ({ companies: useCompanyOptions(), customers: useCustomerOptions(), employees: useEmployeeOptions() })

export function projectToForm(r: Project): Record<string, string> {
  const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
  const a = r.site_address
  return {
    company_uuid: r.company?.uuid ?? '',
    code: r.code,
    name: r.name,
    customer_uuid: r.customer?.uuid ?? '',
    technical_manager_uuid: r.technical_manager?.uuid ?? '',
    foreman_uuid: r.foreman?.uuid ?? '',
    start_date: s(r.start_date),
    duration_months: s(r.duration_months),
    expected_end_date: s(r.expected_end_date),
    actual_end_date: s(r.actual_end_date),
    contract_value: s(r.contract_value),
    built_area: s(r.built_area),
    social_charges_regime: s(r.social_charges_regime),
    cno: s(r.cno),
    progress: s(r.progress),
    description: s(r.description),
    postal_code: s(a?.postal_code),
    street: s(a?.street),
    number: s(a?.number),
    complement: s(a?.complement),
    district: s(a?.district),
    city: s(a?.city),
    state: s(a?.state),
  }
}

/** Corpo para a API: os campos de endereço viram `site_address` (vazio = não mexe no endereço). */
export function projectBody(body: Record<string, unknown>): Record<string, unknown> {
  const out = { ...body }
  const site: Record<string, unknown> = {}
  for (const f of ADDRESS_FIELDS) {
    site[f] = out[f] ?? null
    delete out[f]
  }
  if (site.street) out.site_address = site
  return out
}
