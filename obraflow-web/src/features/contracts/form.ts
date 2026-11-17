import { useQuery } from '@tanstack/react-query'
import { api } from '@/features/registry/api'
import type { FieldDef } from '@/features/registry/components/EntityFormModal'
import { kindOptions } from './api'

export const contractFields: FieldDef[] = [
  { name: 'kind', label: 'Tipo de contrato', type: 'radio', options: kindOptions, required: true, createOnly: true, full: true },
  { name: 'customer_uuid', label: 'Cliente', type: 'select', options: 'customers', required: true, full: true, visible: (v) => v.kind === 'customer', help: 'Cliente da mesma empresa da obra.' },
  { name: 'supplier_uuid', label: 'Fornecedor/empreiteiro', type: 'select', options: 'suppliers', required: true, full: true, visible: (v) => v.kind === 'supplier', help: 'Fornecedor da mesma empresa da obra.' },
  { name: 'code', label: 'Número do contrato', placeholder: `Automático: CTR-001/${new Date().getFullYear()}`, help: 'Deixe em branco para gerar CTR-NNN/AAAA pelo ano do início.' },
  { name: 'starts_at', label: 'Início', type: 'date', required: true },
  { name: 'original_end_date', label: 'Término', type: 'date' },
  { name: 'object', label: 'Objeto / descrição', type: 'textarea', required: true, full: true },
  { name: 'retention_percent', label: 'Retenção (%)', type: 'number', step: '0.01', help: 'Caução retida em cada medição (ex.: 5). Opcional.' },
  { name: 'signed_at', label: 'Data de assinatura', type: 'date', help: 'Pode ser informada ao ativar o contrato.' },
  { name: 'readjustment_rule', label: 'Regra de reajuste', type: 'textarea', full: true, more: true, help: 'Só o texto (o cálculo de reajuste fica para depois — D-P50).' },
  { name: 'notes', label: 'Observações', type: 'textarea', full: true, more: true },
]

/** Clientes e fornecedores da empresa da obra (o contrato só aceita partes da mesma empresa — D-P46). */
function usePartyOptions(kind: 'customers' | 'suppliers', companyUuid?: string) {
  return useQuery({
    queryKey: [kind, 'by-company', companyUuid],
    queryFn: async () =>
      (await api[kind].list({ company_uuid: companyUuid, status: 'active', per_page: 100 })).data.map((p) => ({ value: p.uuid, label: p.name ?? p.trade_name ?? p.legal_name ?? '' })),
    enabled: !!companyUuid,
    staleTime: 60_000,
  }).data
}

export function usePartyLookups(companyUuid?: string) {
  const customers = usePartyOptions('customers', companyUuid)
  const suppliers = usePartyOptions('suppliers', companyUuid)
  return { customers: customers ?? [], suppliers: suppliers ?? [] }
}
