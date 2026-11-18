import { useQuery } from '@tanstack/react-query'
import { StatusBadge } from '@/components/data-display/indicators'
import { useAuth } from '@/features/auth/auth-context'
import { api, statusLabel, statusOptions, type Option } from '@/features/registry/api'
import type { FieldDef } from '@/features/registry/components/EntityFormModal'
import { ResourcePage, type ResourceConfig } from '@/features/registry/components/ResourcePage'
import { useJobTitleOptions, useSupplierOptions, useUnitOptions } from '@/features/registry/lookups'
import { clientPaged } from '@/features/registry/paging'
import { formatCurrency, formatDateOrDash } from '@/utils/format'
import { catalogApi, catalogKindOptions, kindLabel, kindSingular, OWN_VALUES, ownCompositionsApi, UF_OPTIONS, type CatalogGroup, type CatalogResource, type OwnComposition, type ResourceKind } from './api'
import { ImportButton } from './ImportModal'
import { LaborCostButton } from './LaborCostModal'

/** Grupos do tipo: raízes (filtro "grupo") e subgrupos ("subgrupo"); no formulário, "Grupo › Subgrupo". */
function useGroupLookups(kind: ResourceKind) {
  const { can } = useAuth()
  const path = kind === 'equipment' ? null : kind
  const q = useQuery({
    queryKey: ['catalog-groups', kind],
    queryFn: () => (path ? catalogApi.groups.all(path) : api.resourceGroups.all().then((g) => g as unknown as CatalogGroup[])),
    enabled: can(kind === 'equipment' ? 'equipment.view' : 'resources.view'),
    staleTime: 60_000,
  })
  const all = q.data ?? []
  const name = new Map(all.map((g) => [g.id, g.name]))
  const sort = (o: Option[]) => o.sort((a, b) => a.label.localeCompare(b.label))
  return {
    groupRoots: sort(all.filter((g) => g.parent_id === null).map((g) => ({ value: String(g.id), label: g.name }))),
    groupSubs: sort(all.filter((g) => g.parent_id !== null).map((g) => ({ value: String(g.id), label: `${name.get(g.parent_id!) ?? '?'} › ${g.name}` }))),
    groupAll: sort(all.map((g) => ({ value: String(g.id), label: g.parent_id ? `${name.get(g.parent_id) ?? '?'} › ${g.name}` : g.name }))),
  }
}

function useEquipmentOptions(): Option[] {
  const { can } = useAuth()
  const q = useQuery({ queryKey: ['equipment', 'options'], queryFn: () => api.equipment.list({ per_page: 100 }), enabled: can('equipment.view'), staleTime: 60_000 })
  return (q.data?.data ?? []).map((e) => ({ value: e.uuid, label: `${e.code ? `${e.code} — ` : ''}${e.name}` }))
}

const str = (v: unknown) => (v === null || v === undefined ? '' : String(v))

function resourceConfig(kind: ResourceKind): ResourceConfig<CatalogResource> {
  const fields: FieldDef[] = [
    { name: 'name', label: 'Nome', required: true, full: true },
    { name: 'unit_id', label: 'Unidade de medida', type: 'select', options: 'units', required: true },
    { name: 'code', label: 'Código' },
    { name: 'group_id', label: 'Grupo / subgrupo', type: 'select', options: 'groupAll' },
    {
      name: 'price',
      label: kind === 'labor' ? 'Preço (R$ por unidade)' : 'Preço (R$)',
      type: 'number',
      step: '0.01',
      help: kind === 'labor' ? 'Pode ser calculado pelo balanço (ícone de calculadora na lista): o balanço vigente substitui o valor informado.' : undefined,
    },
    ...(kind === 'labor' ? [{ name: 'job_title_id', label: 'Cargo/Função', type: 'select', options: 'jobTitles' } satisfies FieldDef] : []),
    ...(kind === 'material' || kind === 'equipment' ? [{ name: 'brand', label: 'Marca' } satisfies FieldDef] : []),
    ...(kind === 'equipment'
      ? [
          { name: 'model', label: 'Modelo' } satisfies FieldDef,
          { name: 'equipment_uuid', label: 'Equipamento do patrimônio', type: 'select', options: 'equipment', help: 'Opcional: liga o custo ao bem físico cadastrado em Patrimônio (D-P38).' } satisfies FieldDef,
        ]
      : []),
    { name: 'main_supplier_uuid', label: 'Fornecedor principal', type: 'select', options: 'suppliers', more: true },
    { name: 'price_date', label: 'Data do preço', type: 'date', more: true },
    { name: 'status', label: 'Situação', type: 'select', options: statusOptions, placeholder: 'Ativo (padrão)', more: true },
    { name: 'notes', label: 'Observações', type: 'textarea', full: true, more: true },
  ]

  return {
    title: kindLabel[kind],
    description: kind === 'labor' ? 'Mão de obra com preço por hora (informado ou pelo balanço).' : kind === 'equipment' ? 'Custo de uso de equipamentos para orçamento (o bem físico fica em Patrimônio).' : undefined,
    singular: kindSingular[kind],
    queryKey: `catalog-${kind}`,
    managePermission: 'resources.manage',
    fetch: (p) => catalogApi.list({ ...p, kind }),
    rowKey: (r) => r.uuid,
    searchPlaceholder: 'Buscar por nome',
    formSize: 'xl',
    useLookups: () => ({
      ...useGroupLookups(kind),
      units: useUnitOptions(),
      suppliers: useSupplierOptions(),
      jobTitles: useJobTitleOptions(),
      equipment: useEquipmentOptions(),
    }),
    filters: [
      { name: 'parent_group_id', placeholder: 'Selecione um grupo', options: 'groupRoots' },
      { name: 'group_id', placeholder: 'Selecione um subgrupo', options: 'groupSubs' },
    ],
    columns: [
      { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{r.name}</span>, sortValue: (r) => r.name },
      { key: 'unit', header: 'Unidade de medida', cell: (r) => r.unit?.code ?? '' },
      { key: 'group', header: 'Grupo', cell: (r) => (r.group ? (r.group.parent_id ? r.group.parent_name : r.group.name) : '') },
      { key: 'subgroup', header: 'Subgrupo', cell: (r) => (r.group?.parent_id ? r.group.name : '') },
      ...(kind === 'labor' ? [{ key: 'job', header: 'Cargo/Função', cell: (r: CatalogResource) => r.job_title?.name ?? '' }] : []),
      {
        key: 'price',
        header: kind === 'labor' ? 'Preço (R$/h)' : 'Preço (R$)',
        align: 'right',
        cell: (r) => (r.price === null ? <span className="text-muted-foreground">sem preço</span> : formatCurrency(Number(r.price))),
        sortValue: (r) => Number(r.price ?? -1),
      },
      { key: 'date', header: 'Data do preço', cell: (r) => formatDateOrDash(r.price_date) },
      { key: 'status', header: 'Situação', cell: (r) => (r.status === 'active' ? '' : <StatusBadge tone="neutral">{statusLabel(r.status)}</StatusBadge>) },
    ],
    rowActions: kind === 'labor' ? (r) => <LaborCostButton resource={r} /> : undefined,
    toolbarExtra: kind === 'material' || kind === 'equipment' ? ({ canManage, refresh }) => <ImportButton kind={kind} canManage={canManage} onDone={refresh} /> : undefined,
    fields,
    toForm: (r) => ({
      name: r.name,
      code: str(r.code),
      unit_id: str(r.unit?.id),
      group_id: str(r.group?.id),
      price: str(r.price),
      job_title_id: str(r.job_title?.id),
      brand: str(r.brand),
      model: str(r.model),
      equipment_uuid: r.equipment?.uuid ?? '',
      main_supplier_uuid: r.main_supplier?.uuid ?? '',
      price_date: str(r.price_date),
      status: r.status,
      notes: str(r.notes),
    }),
    create: (b) => catalogApi.create({ ...b, kind }),
    update: (r, b) => catalogApi.update(r.uuid, b),
    remove: (r) => catalogApi.remove(r.uuid),
    invalidates: ['catalog-search'],
  }
}

const groupsConfig: ResourceConfig<CatalogGroup & { path: string }> = {
  title: 'Grupos de insumos',
  description: 'Grupos e subgrupos de materiais, mão de obra e serviços (dois níveis). Os de equipamento ficam em Patrimônio.',
  singular: 'grupo',
  queryKey: 'catalog-groups',
  managePermission: 'resources.manage',
  fetch: async (p) => {
    const all = await catalogApi.groups.all()
    const name = new Map(all.map((g) => [g.id, g.name]))
    const rows = all.map((g) => ({ ...g, path: g.parent_id ? `${name.get(g.parent_id)} › ${g.name}` : g.name }))
    rows.sort((a, b) => a.kind.localeCompare(b.kind) || a.path.localeCompare(b.path))
    return clientPaged(async () => rows, (r) => r.path, (r, q) => !q.kind || r.kind === q.kind)(p)
  },
  rowKey: (r) => String(r.id),
  filters: [{ name: 'kind', placeholder: 'Todos os tipos', options: catalogKindOptions }],
  useLookups: () => {
    const q = useQuery({ queryKey: ['catalog-groups', 'roots'], queryFn: () => catalogApi.groups.all(), staleTime: 30_000 })
    return {
      roots: (q.data ?? []).filter((g) => g.parent_id === null).map((g) => ({ value: String(g.id), label: `${catalogKindOptions.find((k) => k.value === g.kind)?.label} — ${g.name}` })),
    }
  },
  columns: [
    { key: 'path', header: 'Grupo', cell: (r) => <span className={r.parent_id ? 'pl-5' : 'font-medium'}>{r.path}</span> },
    { key: 'kind', header: 'Tipo', cell: (r) => catalogKindOptions.find((k) => k.value === r.kind)?.label },
    { key: 'level', header: 'Nível', cell: (r) => (r.parent_id ? 'Subgrupo' : 'Grupo') },
  ],
  fields: [
    { name: 'kind', label: 'Tipo', type: 'radio', options: catalogKindOptions, required: true, createOnly: true, full: true },
    { name: 'name', label: 'Nome', required: true },
    { name: 'parent_id', label: 'Grupo pai', type: 'select', options: 'roots', placeholder: 'Nenhum (grupo raiz)', help: 'O grupo pai precisa ser do mesmo tipo.' },
  ],
  toForm: (r) => ({ kind: r.kind, name: r.name, parent_id: str(r.parent_id) }),
  create: (b) => catalogApi.groups.create(b),
  update: (r, b) => catalogApi.groups.update(r.id, b),
  remove: (r) => catalogApi.groups.remove(r.id),
}

/** Composições próprias simplificadas (6B, D-P87): um valor por tipo; equipamento e mão de obra por regime (D-P88). */
const money = (name: string, label: string, help?: string): FieldDef => ({ name, label, type: 'number', step: '0.01', help })
const ownConfig: ResourceConfig<OwnComposition> = {
  title: 'Composições próprias',
  description: 'Composições simplificadas da empresa: o custo de uma unidade do serviço, separado por tipo. Usadas no planejamento das obras (botão "Comp. própria").',
  singular: 'composição',
  queryKey: 'own-compositions',
  managePermission: 'resources.manage',
  fetch: (p) => ownCompositionsApi.list(p),
  rowKey: (r) => r.uuid,
  searchPlaceholder: 'Buscar por nome ou código',
  formSize: 'xl',
  useLookups: () => ({ units: useUnitOptions() }),
  filters: [{ name: 'status', placeholder: 'Todas as situações', options: statusOptions }],
  columns: [
    { key: 'code', header: 'Código', cell: (r) => r.code ?? '' },
    { key: 'name', header: 'Nome', cell: (r) => <span className="font-medium">{r.name}</span>, sortValue: (r) => r.name },
    { key: 'unit', header: 'Unidade', cell: (r) => r.unit?.code ?? '' },
    { key: 'date', header: 'Referência', cell: (r) => [formatDateOrDash(r.reference_date), r.state].filter(Boolean).join(' · ') },
    { key: 't_none', header: 'Sem encargos', align: 'right', cell: (r) => formatCurrency(Number(r.totals.not_applicable)), sortValue: (r) => Number(r.totals.not_applicable) },
    { key: 't_relief', header: 'Desonerado', align: 'right', cell: (r) => formatCurrency(Number(r.totals.with_relief)), sortValue: (r) => Number(r.totals.with_relief) },
    { key: 't_no_relief', header: 'Não desonerado', align: 'right', cell: (r) => formatCurrency(Number(r.totals.without_relief)), sortValue: (r) => Number(r.totals.without_relief) },
    { key: 'status', header: 'Situação', cell: (r) => (r.status === 'active' ? '' : <StatusBadge tone="neutral">{statusLabel(r.status)}</StatusBadge>) },
  ],
  fields: [
    { name: 'name', label: 'Nome', required: true, full: true },
    { name: 'unit_id', label: 'Unidade de medida', type: 'select', options: 'units', required: true },
    { name: 'code', label: 'Código' },
    { name: 'reference_date', label: 'Data de referência', type: 'date', required: true },
    { name: 'state', label: 'Estado (UF)', type: 'select', options: UF_OPTIONS, placeholder: 'Opcional' },
    money('material_value', 'Materiais (R$)'),
    money('service_value', 'Serviços (R$)'),
    money('other_value', 'Outros (R$)'),
    money('equipment_value_none', 'Equipamentos — sem encargos (R$)', 'Equipamento e mão de obra têm um valor por regime de encargos. No planejamento vale o regime da obra.'),
    money('equipment_value_relief', 'Equipamentos — desonerado (R$)'),
    money('equipment_value_no_relief', 'Equipamentos — não desonerado (R$)'),
    money('labor_value_none', 'Cargo/Função — sem encargos (R$)'),
    money('labor_value_relief', 'Cargo/Função — desonerado (R$)'),
    money('labor_value_no_relief', 'Cargo/Função — não desonerado (R$)'),
    { name: 'status', label: 'Situação', type: 'select', options: statusOptions, placeholder: 'Ativo (padrão)', more: true },
    { name: 'notes', label: 'Observações', type: 'textarea', full: true, more: true },
  ],
  toForm: (r) => ({
    name: r.name,
    unit_id: str(r.unit?.id),
    code: str(r.code),
    reference_date: str(r.reference_date),
    state: str(r.state),
    ...Object.fromEntries(OWN_VALUES.map((k) => [k, str(r[k])])),
    status: r.status,
    notes: str(r.notes),
  }),
  create: (b) => ownCompositionsApi.create(b),
  update: (r, b) => ownCompositionsApi.update(r.uuid, b),
  remove: (r) => ownCompositionsApi.remove(r.uuid),
}

const configs = { material: resourceConfig('material'), labor: resourceConfig('labor'), equipment: resourceConfig('equipment'), service: resourceConfig('service') }

export const MaterialsPage = () => <ResourcePage config={configs.material} />
export const LaborPage = () => <ResourcePage config={configs.labor} />
export const EquipmentCostPage = () => <ResourcePage config={configs.equipment} />
export const ServicesPage = () => <ResourcePage config={configs.service} />
export const CatalogGroupsPage = () => <ResourcePage config={groupsConfig} />
export const OwnCompositionsPage = () => <ResourcePage config={ownConfig} />
