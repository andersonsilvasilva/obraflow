import {
  BadgeDollarSign,
  BookOpenCheck,
  Building2,
  CalendarRange,
  ClipboardList,
  FileSignature,
  FileText,
  HardHat,
  LayoutDashboard,
  Package,
  PieChart,
  Ruler,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Truck,
  UserCog,
  Users,
  Warehouse,
  Calculator,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
}
export interface NavGroup {
  label: string
  items: NavItem[]
}

export const navigation: NavGroup[] = [
  { label: 'Dashboard', items: [{ label: 'Visão geral', path: '/', icon: LayoutDashboard }] },
  {
    label: 'Gestão',
    items: [
      { label: 'Empresas', path: '/empresas', icon: Building2 },
      { label: 'Clientes', path: '/clientes', icon: Users },
      { label: 'Fornecedores', path: '/fornecedores', icon: Truck },
      { label: 'Obras', path: '/obras', icon: HardHat },
      { label: 'Contratos', path: '/contratos', icon: FileSignature },
    ],
  },
  {
    label: 'Engenharia',
    items: [
      { label: 'Orçamentos', path: '/orcamentos', icon: Calculator },
      { label: 'Planejamento', path: '/planejamento', icon: CalendarRange },
      { label: 'Diário de Obras', path: '/diario-de-obras', icon: BookOpenCheck },
      { label: 'Medições', path: '/medicoes', icon: Ruler },
    ],
  },
  {
    label: 'Operação',
    items: [
      { label: 'Compras', path: '/compras', icon: ShoppingCart },
      { label: 'Suprimentos', path: '/suprimentos', icon: ClipboardList },
      { label: 'Estoque', path: '/estoque', icon: Warehouse },
      { label: 'Equipes', path: '/equipes', icon: Package },
    ],
  },
  {
    label: 'Administração',
    items: [
      { label: 'Financeiro', path: '/financeiro', icon: BadgeDollarSign },
      { label: 'Documentos', path: '/documentos', icon: FileText },
      { label: 'Relatórios', path: '/relatorios', icon: PieChart },
    ],
  },
  {
    label: 'Configurações',
    items: [
      { label: 'Minha conta', path: '/configuracoes/minha-conta', icon: UserCog },
      { label: 'Usuários e permissões', path: '/configuracoes/usuarios', icon: ShieldCheck },
      { label: 'Configurações da empresa', path: '/configuracoes/empresa', icon: Settings },
    ],
  },
]

/** Nome amigável por segmento de rota, usado pelo Breadcrumb. */
export const routeLabels: Record<string, string> = {
  empresas: 'Empresas',
  clientes: 'Clientes',
  fornecedores: 'Fornecedores',
  obras: 'Obras',
  contratos: 'Contratos',
  orcamentos: 'Orçamentos',
  novo: 'Novo',
  preencher: 'Preenchimento',
  finalizacao: 'Finalização',
  inteligente: 'Orçamento inteligente',
  planejamento: 'Planejamento',
  'diario-de-obras': 'Diário de Obras',
  medicoes: 'Medições',
  compras: 'Compras',
  suprimentos: 'Suprimentos',
  estoque: 'Estoque',
  equipes: 'Equipes',
  financeiro: 'Financeiro',
  documentos: 'Documentos',
  relatorios: 'Relatórios',
  configuracoes: 'Configurações',
  'minha-conta': 'Minha conta',
  usuarios: 'Usuários e permissões',
  empresa: 'Configurações da empresa',
}
