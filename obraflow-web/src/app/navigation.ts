import {
  BadgeDollarSign,
  BookOpenCheck,
  Calculator,
  CalendarRange,
  ClipboardList,
  Compass,
  Clock,
  FileText,
  FolderPlus,
  HardHat,
  LayoutDashboard,
  PieChart,
  Ruler,
  ShoppingCart,
  Sparkles,
  Warehouse,
  type LucideIcon,
} from 'lucide-react'

/**
 * Menu lateral (padrão G2): até 3 níveis. `permission` = permissão de CONSULTA exigida (item some sem ela — T-03);
 * `demo` = tela-protótipo com dados demonstrativos (T-01).
 */
export interface NavNode {
  label: string
  path?: string
  icon?: LucideIcon
  permission?: string
  demo?: boolean
  children?: NavNode[]
}

export const navigation: NavNode[] = [
  { label: 'Primeiros passos', path: '/primeiros-passos', icon: Compass },
  { label: 'Visão geral', path: '/', icon: LayoutDashboard, demo: true },
  { label: 'Obras', path: '/obras', icon: HardHat, permission: 'projects.view' },
  {
    label: 'Cadastros',
    icon: FolderPlus,
    children: [
      { label: 'Empresas', path: '/cadastros/empresas', permission: 'companies.view' },
      { label: 'Clientes', path: '/cadastros/clientes', permission: 'customers.view' },
      { label: 'Fornecedores', path: '/cadastros/fornecedores', permission: 'suppliers.view' },
      { label: 'Unidades de medida', path: '/cadastros/unidades', permission: 'units.view' },
      {
        label: 'Equipes',
        children: [
          { label: 'Funcionários', path: '/cadastros/funcionarios', permission: 'employees.view' },
          { label: 'Cargo/Função', path: '/cadastros/cargos', permission: 'employees.view' },
          { label: 'Setores', path: '/cadastros/setores', permission: 'employees.view' },
        ],
      },
      {
        label: 'Recursos',
        children: [
          { label: 'Materiais', path: '/cadastros/materiais', permission: 'resources.view' },
          { label: 'Mão de obra', path: '/cadastros/mao-de-obra', permission: 'resources.view' },
          { label: 'Equipamentos (custo)', path: '/cadastros/equipamentos-custo', permission: 'resources.view' },
          { label: 'Serviços', path: '/cadastros/servicos', permission: 'resources.view' },
          { label: 'Composições próprias', path: '/cadastros/composicoes-proprias', permission: 'resources.view' },
          { label: 'Grupos de insumos', path: '/cadastros/grupos-insumos', permission: 'resources.view' },
        ],
      },
      {
        label: 'Patrimônio',
        children: [
          { label: 'Equipamentos', path: '/cadastros/equipamentos', permission: 'equipment.view' },
          { label: 'Grupos de equipamentos', path: '/cadastros/grupos', permission: 'equipment.view' },
        ],
      },
    ],
  },
  { label: 'Orçamentos', path: '/orcamentos', icon: Calculator, permission: 'budgets.view' },
  { label: 'Suprimentos', path: '/suprimentos', icon: ClipboardList, demo: true },
  {
    label: 'Em preparação',
    icon: Clock,
    children: [
      { label: 'Orçamento inteligente (IA)', path: '/orcamento-inteligente', icon: Sparkles, demo: true },
      { label: 'Planejamento', path: '/planejamento', icon: CalendarRange },
      { label: 'Diário de Obras', path: '/diario-de-obras', icon: BookOpenCheck },
      { label: 'Medições', path: '/medicoes', icon: Ruler },
      { label: 'Compras', path: '/compras', icon: ShoppingCart },
      { label: 'Estoque', path: '/estoque', icon: Warehouse },
      { label: 'Financeiro', path: '/financeiro', icon: BadgeDollarSign },
      { label: 'Documentos', path: '/documentos', icon: FileText },
      { label: 'Relatórios', path: '/relatorios', icon: PieChart },
    ],
  },
]

/** Remove o que o usuário não pode consultar e grupos que ficaram vazios. */
export function filterNavigation(nodes: NavNode[], can: (permission: string) => boolean): NavNode[] {
  return nodes.flatMap((node) => {
    if (node.permission && !can(node.permission)) return []
    if (!node.children) return [node]
    const children = filterNavigation(node.children, can)
    return children.length ? [{ ...node, children }] : []
  })
}

/** Primeiro caminho navegável de um nó (usado no menu recolhido). */
export function firstPath(node: NavNode): string | undefined {
  return node.path ?? node.children?.map(firstPath).find(Boolean)
}

/** Nome amigável por segmento de rota, usado pelo Breadcrumb. */
export const routeLabels: Record<string, string> = {
  'primeiros-passos': 'Primeiros passos',
  cadastros: 'Cadastros',
  empresas: 'Empresas',
  clientes: 'Clientes',
  fornecedores: 'Fornecedores',
  unidades: 'Unidades de medida',
  funcionarios: 'Funcionários',
  cargos: 'Cargo/Função',
  setores: 'Setores',
  equipamentos: 'Equipamentos',
  grupos: 'Grupos de equipamentos',
  materiais: 'Materiais',
  'mao-de-obra': 'Mão de obra',
  'equipamentos-custo': 'Equipamentos (custo)',
  servicos: 'Serviços',
  'composicoes-proprias': 'Composições próprias',
  'grupos-insumos': 'Grupos de insumos',
  obras: 'Obras',
  contratos: 'Contratos',
  orcamentos: 'Orçamentos',
  'orcamento-inteligente': 'Orçamento inteligente (IA)',
  planejamento: 'Planejamento',
  'diario-de-obras': 'Diário de Obras',
  medicoes: 'Medições',
  compras: 'Compras',
  suprimentos: 'Suprimentos',
  estoque: 'Estoque',
  financeiro: 'Financeiro',
  documentos: 'Documentos',
  relatorios: 'Relatórios',
}


