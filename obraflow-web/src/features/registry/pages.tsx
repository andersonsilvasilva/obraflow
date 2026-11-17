import { StatusBadge } from '@/components/data-display/indicators'
import { formatDocument } from '@/utils/format'
import { formatPhone } from '@/utils/masks'
import { api, statusLabel, statusOptions, type CatalogItem, type Company, type Employee, type Equipment, type Option, type Party, type ResourceGroup, type Unit } from './api'
import { CertificationsButton } from './CertificationsModal'
import type { FieldDef } from './components/EntityFormModal'
import { ResourcePage, type ResourceConfig } from './components/ResourcePage'
import { clientPaged } from './paging'
import type { QuickAddConfig } from './components/CatalogQuickModal'
import {
  useCompanyOptions,
  useDepartmentOptions,
  useEmployeeOptions,
  useGroupOptions,
  useJobTitleOptions,
  useSupplierOptions,
  useUnitOptions,
} from './lookups'

const str = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const statusCell = (s: string) => <StatusBadge tone={s === 'active' ? 'success' : 'neutral'}>{statusLabel(s)}</StatusBadge>
const statusField: FieldDef = { name: 'status', label: 'Situação', type: 'select', options: statusOptions, placeholder: 'Ativo (padrão)' }
const statusFilter = { name: 'status', placeholder: 'Todas as situações', options: statusOptions }
const companyField: FieldDef = { name: 'company_uuid', label: 'Empresa', type: 'select', options: 'companies', required: true, createOnly: true, full: true }

// ================================================================== Empresas
const companies: ResourceConfig<Company> = {
  title: 'Empresas',
  description: 'Pessoas jurídicas administradas pela organização.',
  singular: 'empresa',
  queryKey: 'companies',
  managePermission: 'companies.manage',
  fetch: (p) => api.companies.list(p),
  rowKey: (r) => r.uuid,
  searchPlaceholder: 'Buscar por nome ou CNPJ',
  filters: [statusFilter],
  columns: [
    { key: 'name', header: 'Razão social', cell: (r) => <span className="font-medium">{r.legal_name}</span>, sortValue: (r) => r.legal_name },
    { key: 'trade', header: 'Nome fantasia', cell: (r) => r.trade_name ?? '—', sortValue: (r) => r.trade_name ?? '' },
    { key: 'doc', header: 'CNPJ', cell: (r) => formatDocument(r.document_number) },
    { key: 'status', header: 'Situação', cell: (r) => statusCell(r.status) },
  ],
  fields: [
    { name: 'legal_name', label: 'Razão social', required: true, full: true },
    { name: 'trade_name', label: 'Nome fantasia' },
    { name: 'document_number', label: 'CNPJ', required: true, mask: 'cnpj' },
    { name: 'email', label: 'E-mail', type: 'email' },
    { name: 'phone', label: 'Telefone', mask: 'phone' },
    statusField,
    { name: 'state_registration', label: 'Inscrição estadual', more: true },
    { name: 'municipal_registration', label: 'Inscrição municipal', more: true },
  ],
  toForm: (r) => ({ ...mapStrings(r) }),
  create: (b) => api.companies.create(b),
  update: (r, b) => api.companies.update(r.uuid, b),
  remove: (r) => api.companies.remove(r.uuid),
  invalidates: ['companies'],
}

// ================================================================== Clientes e fornecedores
const typeOptions: Option[] = [
  { value: 'person', label: 'Pessoa física' },
  { value: 'company', label: 'Pessoa jurídica' },
]

function partyConfig(kind: 'customers' | 'suppliers'): ResourceConfig<Party> {
  const supplier = kind === 'suppliers'
  const svc = supplier ? api.suppliers : api.customers
  const name = (r: Party) => (supplier ? (r.trade_name || r.legal_name) : r.name) ?? ''
  return {
    title: supplier ? 'Fornecedores' : 'Clientes',
    singular: supplier ? 'fornecedor' : 'cliente',
    queryKey: kind,
    managePermission: `${kind}.manage`,
    fetch: (p) => svc.list(p),
    rowKey: (r) => r.uuid,
    searchPlaceholder: 'Buscar por nome ou documento',
    filters: [
      { name: 'company_uuid', placeholder: 'Todas as empresas', options: 'companies' },
      { name: 'type', placeholder: 'Todos os tipos', options: typeOptions },
      statusFilter,
    ],
    useLookups: () => ({ companies: useCompanyOptions() }),
    columns: [
      { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{name(r)}</span>, sortValue: name },
      { key: 'type', header: 'Tipo', cell: (r) => (r.type === 'person' ? 'Física' : 'Jurídica') },
      { key: 'doc', header: 'CPF/CNPJ', cell: (r) => formatDocument(r.document_number) },
      { key: 'phone', header: 'Telefone', cell: (r) => formatPhone(r.phone) },
      { key: 'status', header: 'Situação', cell: (r) => statusCell(r.status) },
    ],
    fields: [
      companyField,
      { name: 'type', label: 'Tipo', type: 'radio', options: typeOptions, required: true, full: true },
      ...(supplier
        ? [
            { name: 'legal_name', label: 'Razão social / Nome', required: true, full: true } satisfies FieldDef,
            { name: 'trade_name', label: 'Nome fantasia' } satisfies FieldDef,
          ]
        : [{ name: 'name', label: 'Nome', required: true, full: true } satisfies FieldDef]),
      { name: 'document_number', label: 'CPF/CNPJ', required: supplier, mask: 'cpf_cnpj', help: 'CPF para pessoa física, CNPJ para pessoa jurídica.' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'phone', label: 'Telefone', mask: 'phone' },
      statusField,
    ],
    toForm: (r) => mapStrings(r),
    create: (b) => svc.create(b),
    update: (r, b) => svc.update(r.uuid, b),
    remove: (r) => svc.remove(r.uuid),
    invalidates: [kind],
  }
}

// ================================================================== Unidades
const units: ResourceConfig<Unit> = {
  title: 'Unidades de medida',
  singular: 'unidade',
  queryKey: 'units',
  managePermission: 'units.manage',
  fetch: clientPaged(() => api.units.all(), (r) => `${r.code} ${r.name}`, (r, p) => !p.status || r.status === p.status),
  rowKey: (r) => String(r.id),
  searchPlaceholder: 'Buscar por código ou nome',
  filters: [statusFilter],
  columns: [
    { key: 'code', header: 'Código', cell: (r) => <span className="font-medium">{r.code}</span>, sortValue: (r) => r.code },
    { key: 'name', header: 'Nome', cell: (r) => r.name, sortValue: (r) => r.name },
    { key: 'status', header: 'Situação', cell: (r) => statusCell(r.status) },
  ],
  fields: [
    { name: 'code', label: 'Código', required: true, placeholder: 'm², kg, h…' },
    { name: 'name', label: 'Nome', required: true },
    statusField,
  ],
  toForm: (r) => mapStrings(r),
  create: (b) => api.units.create(b),
  update: (r, b) => api.units.update(r.id, b),
}

// ================================================================== Cargos e setores
function catalogConfig(kind: 'jobTitles' | 'departments'): ResourceConfig<CatalogItem> {
  const job = kind === 'jobTitles'
  const svc = api[kind]
  return {
    title: job ? 'Cargo/Função' : 'Setores',
    singular: job ? 'cargo/função' : 'setor',
    queryKey: job ? 'job-titles' : 'departments',
    managePermission: 'employees.manage',
    fetch: clientPaged(() => svc.all(), (r) => r.name, (r, p) => !p.status || r.status === p.status),
    rowKey: (r) => String(r.id),
    filters: [statusFilter],
    columns: [
      { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{r.name}</span>, sortValue: (r) => r.name },
      { key: 'status', header: 'Situação', cell: (r) => statusCell(r.status) },
    ],
    fields: [{ name: 'name', label: 'Nome', required: true, full: true }, statusField],
    toForm: (r) => mapStrings(r),
    create: (b) => svc.create(b),
    update: (r, b) => svc.update(r.id, b),
    remove: (r) => svc.remove(r.id),
  }
}

const quick = (kind: 'jobTitles' | 'departments'): QuickAddConfig => {
  const svc = api[kind]
  return {
    title: kind === 'jobTitles' ? 'Cargo/Função' : 'Setores',
    queryKey: kind === 'jobTitles' ? 'job-titles' : 'departments',
    managePermission: 'employees.manage',
    list: () => svc.all(),
    create: (name) => svc.create({ name }),
    rename: (id, name) => svc.update(id, { name }),
    remove: (id) => svc.remove(id),
  }
}

// ================================================================== Grupos de recursos
const groups: ResourceConfig<ResourceGroup> = {
  title: 'Grupos de recursos',
  description: 'Grupos e subgrupos de equipamentos (dois níveis).',
  singular: 'grupo',
  queryKey: 'resource-groups',
  managePermission: 'equipment.manage',
  fetch: async (p) => {
    const all = await api.resourceGroups.all()
    const byId = new Map(all.map((g) => [g.id, g.name]))
    const withPath = all.map((g) => ({ ...g, path: g.parent_id ? `${byId.get(g.parent_id)} › ${g.name}` : g.name }))
    withPath.sort((a, b) => a.path.localeCompare(b.path))
    return clientPaged(async () => withPath, (r) => r.path)(p)
  },
  rowKey: (r) => String(r.id),
  useLookups: () => ({ roots: useGroupOptions(true) }),
  columns: [
    { key: 'name', header: 'Grupo', cell: (r) => <span className={r.parent_id ? 'pl-5' : 'font-medium'}>{(r as ResourceGroup & { path: string }).path}</span> },
    { key: 'level', header: 'Nível', cell: (r) => (r.parent_id ? 'Subgrupo' : 'Grupo') },
  ],
  fields: [
    { name: 'name', label: 'Nome', required: true },
    { name: 'parent_id', label: 'Grupo pai', type: 'select', options: 'roots', placeholder: 'Nenhum (grupo raiz)', help: 'Deixe vazio para criar um grupo; escolha um grupo para criar um subgrupo.' },
  ],
  toForm: (r) => ({ name: r.name, parent_id: str(r.parent_id) }),
  create: (b) => api.resourceGroups.create(b),
  update: (r, b) => api.resourceGroups.update(r.id, b),
  remove: (r) => api.resourceGroups.remove(r.id),
  invalidates: ['resource-groups-roots'],
}

// ================================================================== Funcionários
const laborOptions: Option[] = [
  { value: 'direct', label: 'Direta' },
  { value: 'indirect', label: 'Indireta' },
]
const contractOptions: Option[] = [
  { value: 'monthly', label: 'Mensalista' },
  { value: 'hourly', label: 'Horista' },
  { value: 'piecework', label: 'Empreitada' },
  { value: 'daily', label: 'Diarista' },
]

const employees: ResourceConfig<Employee> = {
  title: 'Funcionários',
  description: 'Equipe própria das empresas. Dados pessoais mínimos (LGPD).',
  singular: 'funcionário',
  queryKey: 'employees',
  managePermission: 'employees.manage',
  fetch: (p) => api.employees.list(p),
  rowKey: (r) => r.uuid,
  searchPlaceholder: 'Buscar por nome ou matrícula',
  formSize: 'xl',
  useLookups: () => ({
    companies: useCompanyOptions(),
    jobTitles: useJobTitleOptions(),
    departments: useDepartmentOptions(),
    employees: useEmployeeOptions(),
  }),
  filters: [
    { name: 'company_uuid', placeholder: 'Todas as empresas', options: 'companies' },
    { name: 'job_title_id', placeholder: 'Todos os cargos', options: 'jobTitles' },
    { name: 'department_id', placeholder: 'Todos os setores', options: 'departments' },
    statusFilter,
  ],
  columns: [
    { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{r.name}</span>, sortValue: (r) => r.name },
    { key: 'reg', header: 'Matrícula', cell: (r) => r.registration_number ?? '—' },
    { key: 'job', header: 'Cargo/Função', cell: (r) => r.job_title?.name ?? '—', sortValue: (r) => r.job_title?.name ?? '' },
    { key: 'dept', header: 'Setor', cell: (r) => r.department?.name ?? '—' },
    { key: 'doc', header: 'CPF', cell: (r) => formatDocument(r.document_number) },
    { key: 'status', header: 'Situação', cell: (r) => statusCell(r.status) },
  ],
  rowActions: (r) => <CertificationsButton employee={r} />,
  fields: [
    companyField,
    { name: 'name', label: 'Nome', required: true, full: true },
    { name: 'job_title_id', label: 'Cargo/Função', type: 'select', options: 'jobTitles', quickAdd: quick('jobTitles') },
    { name: 'department_id', label: 'Setor', type: 'select', options: 'departments', quickAdd: quick('departments') },
    { name: 'labor_type', label: 'Mão de obra', type: 'radio', options: laborOptions },
    { name: 'contract_type', label: 'Tipo de contrato', type: 'select', options: contractOptions },
    { name: 'document_number', label: 'CPF', mask: 'cpf', help: 'Opcional. Visível por completo só para quem administra funcionários.' },
    { name: 'registration_number', label: 'Matrícula' },
    { name: 'phone', label: 'Telefone', mask: 'phone', more: true },
    { name: 'email', label: 'E-mail', type: 'email', more: true },
    { name: 'supervisor_uuid', label: 'Responsável', type: 'select', options: 'employees', more: true, help: 'Funcionário da mesma empresa.' },
    { name: 'hired_at', label: 'Admissão', type: 'date', more: true },
    { name: 'terminated_at', label: 'Desligamento', type: 'date', more: true },
    { ...statusField, more: true },
  ],
  toForm: (r) => ({
    ...mapStrings(r),
    company_uuid: r.company?.uuid ?? '',
    job_title_id: str(r.job_title?.id),
    department_id: str(r.department?.id),
    supervisor_uuid: r.supervisor?.uuid ?? '',
    // CPF mascarado não volta ao formulário (evita gravar a máscara)
    document_number: r.document_number && /^\d+$/.test(r.document_number) ? r.document_number : '',
  }),
  create: (b) => api.employees.create(b),
  update: (r, b) => {
    if (b.document_number === null && r.document_number && !/^\d+$/.test(r.document_number)) delete b.document_number
    return api.employees.update(r.uuid, b)
  },
  remove: (r) => api.employees.remove(r.uuid),
  invalidates: ['employees'],
}

// ================================================================== Equipamentos
const ownershipOptions: Option[] = [
  { value: 'owned', label: 'Próprio' },
  { value: 'rented', label: 'Alugado' },
]
const equipmentStatus: Option[] = [
  { value: 'available', label: 'Disponível' },
  { value: 'in_use', label: 'Em uso' },
  { value: 'maintenance', label: 'Em manutenção' },
  { value: 'retired', label: 'Desativado' },
]
const equipmentTone = { available: 'success', in_use: 'primary', maintenance: 'warning', retired: 'neutral' } as const

const equipment: ResourceConfig<Equipment> = {
  title: 'Equipamentos',
  singular: 'equipamento',
  queryKey: 'equipment',
  managePermission: 'equipment.manage',
  fetch: (p) => api.equipment.list(p),
  rowKey: (r) => r.uuid,
  searchPlaceholder: 'Buscar por nome, código ou marca',
  formSize: 'xl',
  useLookups: () => ({ companies: useCompanyOptions(), groups: useGroupOptions(), units: useUnitOptions(), suppliers: useSupplierOptions() }),
  filters: [
    { name: 'group_id', placeholder: 'Selecione um grupo', options: 'groups' },
    { name: 'status', placeholder: 'Todas as situações', options: equipmentStatus },
  ],
  columns: [
    { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{r.name}</span>, sortValue: (r) => r.name },
    { key: 'code', header: 'Código', cell: (r) => r.code ?? '—' },
    { key: 'unit', header: 'Unidade de medida', cell: (r) => r.unit?.code ?? '' },
    { key: 'group', header: 'Grupo', cell: (r) => r.group?.name ?? '' },
    { key: 'own', header: 'Posse', cell: (r) => ownershipOptions.find((o) => o.value === r.ownership)?.label },
    { key: 'status', header: 'Situação', cell: (r) => <StatusBadge tone={equipmentTone[r.status]}>{equipmentStatus.find((o) => o.value === r.status)?.label}</StatusBadge> },
  ],
  fields: [
    companyField,
    { name: 'name', label: 'Nome', required: true, full: true },
    { name: 'code', label: 'Código' },
    { name: 'group_id', label: 'Grupo / subgrupo', type: 'select', options: 'groups' },
    { name: 'unit_id', label: 'Unidade de medida', type: 'select', options: 'units' },
    { name: 'ownership', label: 'Posse', type: 'radio', options: ownershipOptions },
    { name: 'status', label: 'Situação', type: 'select', options: equipmentStatus, placeholder: 'Disponível (padrão)' },
    { name: 'main_supplier_uuid', label: 'Fornecedor principal', type: 'select', options: 'suppliers', help: 'Fornecedor da mesma empresa do equipamento.', more: true },
    { name: 'brand', label: 'Marca', more: true },
    { name: 'model', label: 'Modelo', more: true },
    { name: 'kind', label: 'Tipo', more: true },
    { name: 'condition', label: 'Condição', more: true },
  ],
  toForm: (r) => ({
    ...mapStrings(r),
    company_uuid: r.company?.uuid ?? '',
    group_id: str(r.group?.id),
    unit_id: str(r.unit?.id),
    main_supplier_uuid: r.main_supplier?.uuid ?? '',
  }),
  create: (b) => api.equipment.create(b),
  update: (r, b) => api.equipment.update(r.uuid, b),
  remove: (r) => api.equipment.remove(r.uuid),
}

/** Copia os campos escalares como texto (para o formulário). */
function mapStrings(row: object): Record<string, string> {
  return Object.fromEntries(Object.entries(row).filter(([, v]) => v === null || typeof v !== 'object').map(([k, v]) => [k, str(v)]))
}

export const CompaniesPage = () => <ResourcePage config={companies} />
const customers = partyConfig('customers')
const suppliers = partyConfig('suppliers')
const jobTitles = catalogConfig('jobTitles')
const departments = catalogConfig('departments')

export const CustomersPage = () => <ResourcePage config={customers} />
export const SuppliersPage = () => <ResourcePage config={suppliers} />
export const UnitsPage = () => <ResourcePage config={units} />
export const JobTitlesPage = () => <ResourcePage config={jobTitles} />
export const DepartmentsPage = () => <ResourcePage config={departments} />
export const GroupsPage = () => <ResourcePage config={groups} />
export const EmployeesPage = () => <ResourcePage config={employees} />
export const EquipmentPage = () => <ResourcePage config={equipment} />

