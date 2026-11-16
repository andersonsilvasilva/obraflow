import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/features/auth/auth-context'
import { api, type Option } from './api'

/**
 * Opções para selects e filtros. Cada lista só é buscada se o usuário pode consultá-la (senão fica vazia —
 * a API recusaria com 403). Até 100 itens ativos; listas maiores pedirão busca incremental numa próxima etapa.
 */
function useOptions(key: string, permission: string, load: () => Promise<Option[]>, enabled = true): Option[] {
  const { can } = useAuth()
  const q = useQuery({ queryKey: [key, 'options'], queryFn: load, enabled: enabled && can(permission), staleTime: 60_000 })
  return q.data ?? []
}

export const useCompanyOptions = () =>
  useOptions('companies', 'companies.view', async () =>
    (await api.companies.list({ per_page: 100, status: 'active' })).data.map((c) => ({ value: c.uuid, label: c.trade_name || c.legal_name })),
  )

export const useUnitOptions = () =>
  useOptions('units', 'units.view', async () => (await api.units.all({ status: 'active' })).map((u) => ({ value: String(u.id), label: `${u.code} — ${u.name}` })))

export const useJobTitleOptions = () =>
  useOptions('job-titles', 'employees.view', async () => (await api.jobTitles.all({ status: 'active' })).map((j) => ({ value: String(j.id), label: j.name })))

export const useDepartmentOptions = () =>
  useOptions('departments', 'employees.view', async () => (await api.departments.all({ status: 'active' })).map((d) => ({ value: String(d.id), label: d.name })))

/** Grupos e subgrupos de equipamentos: "Grupo › Subgrupo". `rootsOnly` para escolher o pai. */
export const useGroupOptions = (rootsOnly = false) =>
  useOptions(rootsOnly ? 'resource-groups-roots' : 'resource-groups', 'equipment.view', async () => {
    const all = await api.resourceGroups.all()
    const byId = new Map(all.map((g) => [g.id, g]))
    return all
      .filter((g) => !rootsOnly || g.parent_id === null)
      .map((g) => ({ value: String(g.id), label: g.parent_id ? `${byId.get(g.parent_id)?.name ?? '?'} › ${g.name}` : g.name }))
      .sort((a, b) => a.label.localeCompare(b.label))
  })

export const useSupplierOptions = () =>
  useOptions('suppliers', 'suppliers.view', async () =>
    (await api.suppliers.list({ per_page: 100, status: 'active' })).data.map((s) => ({ value: s.uuid, label: s.trade_name || s.legal_name || '' })),
  )

export const useCustomerOptions = () =>
  useOptions('customers', 'customers.view', async () =>
    (await api.customers.list({ per_page: 100, status: 'active' })).data.map((c) => ({ value: c.uuid, label: c.name ?? '' })),
  )

export const useEmployeeOptions = () =>
  useOptions('employees', 'employees.view', async () =>
    (await api.employees.list({ per_page: 100, status: 'active' })).data.map((e) => ({ value: e.uuid, label: e.name })),
  )
