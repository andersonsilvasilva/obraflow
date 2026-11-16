import type { FieldDef } from '@/features/registry/components/EntityFormModal'
import { useCompanyOptions, useCustomerOptions, useEmployeeOptions } from '@/features/registry/lookups'
import type { Project } from './api'

export const projectFields: FieldDef[] = [
  { name: 'company_uuid', label: 'Empresa', type: 'select', options: 'companies', required: true, createOnly: true, full: true },
  { name: 'code', label: 'Código', required: true, placeholder: 'Ex.: OB-001' },
  { name: 'name', label: 'Nome da obra', required: true },
  { name: 'customer_uuid', label: 'Cliente', type: 'select', options: 'customers', help: 'Cliente da mesma empresa da obra.' },
  { name: 'technical_manager_uuid', label: 'Responsável técnico', type: 'select', options: 'employees', help: 'Funcionário da mesma empresa da obra.' },
  { name: 'start_date', label: 'Início', type: 'date' },
  { name: 'expected_end_date', label: 'Previsão de término', type: 'date' },
  { name: 'contract_value', label: 'Valor do contrato (R$)', type: 'number', step: '0.01', more: true },
  { name: 'progress', label: 'Progresso (%)', type: 'number', step: '1', more: true, help: 'Informado manualmente (D-P27).' },
  { name: 'actual_end_date', label: 'Término real', type: 'date', more: true },
  { name: 'description', label: 'Descrição', type: 'textarea', full: true, more: true },
]

export const useProjectLookups = () => ({ companies: useCompanyOptions(), customers: useCustomerOptions(), employees: useEmployeeOptions() })

export function projectToForm(r: Project): Record<string, string> {
  const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
  return {
    company_uuid: r.company?.uuid ?? '',
    code: r.code,
    name: r.name,
    customer_uuid: r.customer?.uuid ?? '',
    technical_manager_uuid: r.technical_manager?.uuid ?? '',
    start_date: s(r.start_date),
    expected_end_date: s(r.expected_end_date),
    actual_end_date: s(r.actual_end_date),
    contract_value: s(r.contract_value),
    progress: s(r.progress),
    description: s(r.description),
  }
}

