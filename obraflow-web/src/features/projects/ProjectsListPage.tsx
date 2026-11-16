import { useNavigate } from 'react-router-dom'
import { ProgressIndicator, StatusBadge } from '@/components/data-display/indicators'
import { ResourcePage, type ResourceConfig } from '@/features/registry/components/ResourcePage'
import { formatDateOrDash } from '@/utils/format'
import { atLeast, projectsApi, statusName, statusOptions, statusTone, type Project } from './api'
import { projectFields, projectToForm, useProjectLookups } from './form'

/** Obras visíveis ao usuário (D-P24: só as liberadas, salvo quem administra obras). */
export function ProjectsListPage() {
  const navigate = useNavigate()

  const config: ResourceConfig<Project> = {
    title: 'Obras',
    description: 'Obras que você pode acessar. Clique em uma obra para ver fases, equipe e acesso.',
    singular: 'obra',
    queryKey: 'projects',
    managePermission: 'projects.manage',
    fetch: (p) => projectsApi.list(p),
    rowKey: (r) => r.uuid,
    searchPlaceholder: 'Buscar por código ou nome',
    formSize: 'xl',
    useLookups: useProjectLookups,
    filters: [
      { name: 'status', placeholder: 'Todos os status', options: statusOptions },
      { name: 'company_uuid', placeholder: 'Todas as empresas', options: 'companies' },
    ],
    columns: [
      { key: 'code', header: 'Código', cell: (r) => <span className="font-medium">{r.code}</span>, sortValue: (r) => r.code },
      { key: 'name', header: 'Obra', cell: (r) => r.name, sortValue: (r) => r.name },
      { key: 'customer', header: 'Cliente', cell: (r) => r.customer?.name ?? '—' },
      { key: 'status', header: 'Status', cell: (r) => <StatusBadge tone={statusTone[r.status]}>{statusName(r.status)}</StatusBadge> },
      { key: 'progress', header: 'Progresso', cell: (r) => <ProgressIndicator value={r.progress} />, sortValue: (r) => r.progress },
      { key: 'end', header: 'Previsão', cell: (r) => formatDateOrDash(r.expected_end_date) },
    ],
    fields: projectFields,
    toForm: projectToForm,
    create: async (b) => navigate(`/obras/${(await projectsApi.create(b)).uuid}`),
    update: (r, b) => projectsApi.update(r.uuid, b),
    remove: (r) => projectsApi.remove(r.uuid),
    canEditRow: (r) => atLeast(r.access_level, 'edit'),
    onOpen: (r) => navigate(`/obras/${r.uuid}`),
  }

  return <ResourcePage config={config} />
}
