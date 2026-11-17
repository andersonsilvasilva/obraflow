/**
 * Percurso de trabalho recomendado no ObraFlow (do cadastro à aprovação do orçamento).
 * Cada passo diz como saber se já foi feito (consulta à API) e qual permissão precisa para ver/fazer.
 */
export interface Step {
  id: string
  title: string
  description: string
  /** Rota da tela onde o passo é feito. */
  to: string
  action: string
  /** Permissão para CONSULTAR o que o passo cria (sem ela, o passo aparece como "sem acesso"). */
  viewPermission: string
  /** Permissão para FAZER o passo. */
  managePermission: string
  /** Consulta que indica conclusão (total > 0). */
  check: { path: string; query?: Record<string, string> }
  optional?: boolean
  tip?: string
}

export const steps: Step[] = [
  {
    id: 'company',
    title: 'Cadastre a empresa',
    description: 'A pessoa jurídica que executa as obras. Clientes, funcionários, obras e orçamentos pertencem a uma empresa.',
    to: '/cadastros/empresas',
    action: 'Ir para Empresas',
    viewPermission: 'companies.view',
    managePermission: 'companies.manage',
    check: { path: '/companies' },
  },
  {
    id: 'customer',
    title: 'Cadastre o cliente',
    description: 'De quem é a obra. O cliente pertence à mesma empresa.',
    to: '/cadastros/clientes',
    action: 'Ir para Clientes',
    viewPermission: 'customers.view',
    managePermission: 'customers.manage',
    check: { path: '/customers' },
  },
  {
    id: 'employee',
    title: 'Cadastre o responsável técnico',
    description: 'Funcionário que responde pela obra. Cargo e setor podem ser criados na hora, pela engrenagem do formulário.',
    to: '/cadastros/funcionarios',
    action: 'Ir para Funcionários',
    viewPermission: 'employees.view',
    managePermission: 'employees.manage',
    check: { path: '/employees' },
    optional: true,
  },
  {
    id: 'project',
    title: 'Crie a obra',
    description: 'Código, nome, cliente e responsável técnico. Na aba "Acesso" da obra, libere-a para quem não é administrador.',
    to: '/obras',
    action: 'Ir para Obras',
    viewPermission: 'projects.view',
    managePermission: 'projects.manage',
    check: { path: '/projects' },
    tip: 'Quem não administra obras só vê as obras liberadas para ele (e os orçamentos delas).',
  },
  {
    id: 'budget',
    title: 'Crie o orçamento',
    description: 'Tipo "Base de referência (SINAPI)", escolha a base (desonerada ou não desonerada) e vincule à obra.',
    to: '/orcamentos',
    action: 'Ir para Orçamentos',
    viewPermission: 'budgets.view',
    managePermission: 'budgets.manage',
    check: { path: '/budgets' },
    tip: 'O regime de encargos vem da base escolhida e não muda depois.',
  },
  {
    id: 'sheet',
    title: 'Monte a planilha',
    description: 'No orçamento: crie as etapas, adicione itens "Da base de referência" (busque por código ou descrição) e itens manuais. Depois defina BDI, custos indiretos e desconto.',
    to: '/orcamentos',
    action: 'Abrir um orçamento',
    viewPermission: 'budgets.view',
    managePermission: 'budgets.manage',
    check: { path: '/budgets', query: { status: 'in_review' } },
    tip: 'Confira a Curva ABC antes de enviar: ela mostra os serviços e insumos mais pesados.',
  },
  {
    id: 'approval',
    title: 'Envie para revisão e aprove',
    description: '"Enviar para revisão" → quem aprova escolhe "Aprovar" (a versão fica bloqueada) ou "Rejeitar" com motivo. Para mudar um orçamento aprovado, use "Nova versão".',
    to: '/orcamentos',
    action: 'Ir para Orçamentos',
    viewPermission: 'budgets.view',
    managePermission: 'budgets.approve',
    check: { path: '/budgets', query: { status: 'approved' } },
  },
]

export type StepState = 'done' | 'pending' | 'no-access' | 'loading'

/** Estado do passo: sem permissão de consulta → "no-access"; sem resposta ainda → "loading"; total > 0 → "done". */
export function stepState(canView: boolean, total: number | undefined): StepState {
  if (!canView) return 'no-access'
  if (total === undefined) return 'loading'
  return total > 0 ? 'done' : 'pending'
}

/**
 * A planilha conta como feita quando há orçamento já enviado ou aprovado (prova de que tinha itens:
 * a API recusa enviar orçamento vazio).
 */
export function sheetDone(inReview: number | undefined, approved: number | undefined): number | undefined {
  if (inReview === undefined || approved === undefined) return undefined
  return inReview + approved
}

/** Próximo passo obrigatório ainda pendente (o "você está aqui"). */
export function nextStep(states: Record<string, StepState>): string | null {
  return steps.find((s) => !s.optional && states[s.id] === 'pending')?.id ?? null
}
