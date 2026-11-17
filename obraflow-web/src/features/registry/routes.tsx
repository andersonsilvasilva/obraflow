import { Outlet, type RouteObject } from 'react-router-dom'
import { RequirePermission } from '@/features/auth/guards'
import { ContractDetailPage } from '@/features/contracts/ContractDetailPage'
import { CatalogGroupsPage, EquipmentCostPage, LaborPage, MaterialsPage, ServicesPage } from '@/features/catalog/pages'
import { ProjectDetailPage } from '@/features/projects/ProjectDetailPage'
import { ProjectsListPage } from '@/features/projects/ProjectsListPage'
import { CompaniesPage, CustomersPage, DepartmentsPage, EmployeesPage, EquipmentPage, GroupsPage, JobTitlesPage, SuppliersPage, UnitsPage } from './pages'

const p = (permission: string, path: string, element: React.ReactNode): RouteObject => ({
  element: <RequirePermission permission={permission} />,
  children: [{ path, element }],
})

/** Rotas dos cadastros (Fases 2 e 3) e das obras; cada uma exige a permissão de consulta (T-03). */
export const registryRoutes: RouteObject[] = [
  {
    path: 'cadastros',
    element: <Outlet />,
    children: [
      p('companies.view', 'empresas', <CompaniesPage />),
      p('customers.view', 'clientes', <CustomersPage />),
      p('suppliers.view', 'fornecedores', <SuppliersPage />),
      p('units.view', 'unidades', <UnitsPage />),
      p('employees.view', 'funcionarios', <EmployeesPage />),
      p('employees.view', 'cargos', <JobTitlesPage />),
      p('employees.view', 'setores', <DepartmentsPage />),
      p('equipment.view', 'equipamentos', <EquipmentPage />),
      p('equipment.view', 'grupos', <GroupsPage />),
      p('resources.view', 'materiais', <MaterialsPage />),
      p('resources.view', 'mao-de-obra', <LaborPage />),
      p('resources.view', 'equipamentos-custo', <EquipmentCostPage />),
      p('resources.view', 'servicos', <ServicesPage />),
      p('resources.view', 'grupos-insumos', <CatalogGroupsPage />),
    ],
  },
  p('projects.view', 'obras', <ProjectsListPage />),
  p('projects.view', 'obras/:uuid', <ProjectDetailPage />),
  p('contracts.view', 'obras/:uuid/contratos/:contractUuid', <ContractDetailPage />),
]
