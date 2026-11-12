// DADOS DEMONSTRATIVOS — fictícios. Códigos "DEMO-*" não correspondem a tabelas
// oficiais (SINAPI, SUDECAP etc.) e não devem ser usados como referência de preço.
import type { Budget, BudgetGroup, Composition, DashboardData, Party, Project, PurchaseRequest } from '@/types'

export const projects: Project[] = [
  { id: 'p1', code: 'OBR-001', name: 'Residencial Modelo A', customer: 'Cliente Demo 01', manager: 'Responsável Demo A', startDate: '2026-01-12', expectedEndDate: '2027-02-28', budget: 4_850_000, progress: 62, status: 'in_progress' },
  { id: 'p2', code: 'OBR-002', name: 'Galpão Logístico Demo', customer: 'Cliente Demo 02', manager: 'Responsável Demo B', startDate: '2025-08-04', expectedEndDate: '2026-10-30', budget: 3_200_000, progress: 71, status: 'delayed' },
  { id: 'p3', code: 'OBR-003', name: 'Edifício Comercial Demo', customer: 'Cliente Demo 03', manager: 'Responsável Demo A', startDate: '2026-03-02', expectedEndDate: '2027-09-15', budget: 12_400_000, progress: 28, status: 'in_progress' },
  { id: 'p4', code: 'OBR-004', name: 'Reforma Escola Demo', customer: 'Cliente Demo 04', manager: 'Responsável Demo C', startDate: '2025-02-10', expectedEndDate: '2025-12-20', budget: 980_000, progress: 100, status: 'completed' },
  { id: 'p5', code: 'OBR-005', name: 'Condomínio Horizontal Demo', customer: 'Cliente Demo 01', manager: 'Responsável Demo B', startDate: '2026-06-01', expectedEndDate: '2028-03-31', budget: 21_700_000, progress: 5, status: 'planning' },
  { id: 'p6', code: 'OBR-006', name: 'Ponte Rodoviária Demo', customer: 'Cliente Demo 05', manager: 'Responsável Demo C', startDate: '2025-05-19', expectedEndDate: '2026-12-18', budget: 8_900_000, progress: 44, status: 'paused' },
  { id: 'p7', code: 'OBR-007', name: 'Centro de Distribuição Demo', customer: 'Cliente Demo 02', manager: 'Responsável Demo A', startDate: '2025-10-06', expectedEndDate: '2026-11-27', budget: 6_300_000, progress: 66, status: 'in_progress' },
]

export const budgets: Budget[] = [
  { id: 'b1', code: 'ORC-0001', description: 'Estrutura e vedação — Residencial Modelo A', customer: 'Cliente Demo 01', project: 'Residencial Modelo A', owner: 'Orçamentista Demo 1', date: '2026-02-03', total: 1_842_350.4, status: 'approved' },
  { id: 'b2', code: 'ORC-0002', description: 'Fundações — Galpão Logístico Demo', customer: 'Cliente Demo 02', project: 'Galpão Logístico Demo', owner: 'Orçamentista Demo 2', date: '2026-03-18', total: 612_900, status: 'in_review' },
  { id: 'b3', code: 'ORC-0003', description: 'Acabamentos — Edifício Comercial Demo', customer: 'Cliente Demo 03', project: 'Edifício Comercial Demo', owner: 'Orçamentista Demo 1', date: '2026-05-22', total: 2_105_780.9, status: 'draft' },
  { id: 'b4', code: 'ORC-0004', description: 'Instalações elétricas — Escola Demo', customer: 'Cliente Demo 04', project: 'Reforma Escola Demo', owner: 'Orçamentista Demo 2', date: '2025-04-09', total: 184_200, status: 'rejected' },
]

export const compositions: Composition[] = [
  {
    id: 'cp1', code: 'DEMO-C-001', description: 'Alvenaria de vedação em bloco cerâmico (demonstrativo)', unit: 'm²',
    inputs: [
      { id: 'i1', description: 'Bloco cerâmico demonstrativo', type: 'material', unit: 'un', coefficient: 25, unitPrice: 1.2 },
      { id: 'i2', description: 'Argamassa de assentamento demonstrativa', type: 'material', unit: 'm³', coefficient: 0.015, unitPrice: 420 },
      { id: 'i3', description: 'Pedreiro (demonstrativo)', type: 'labor', unit: 'h', coefficient: 0.9, unitPrice: 24 },
      { id: 'i4', description: 'Servente (demonstrativo)', type: 'labor', unit: 'h', coefficient: 0.9, unitPrice: 17 },
    ],
  },
  {
    id: 'cp2', code: 'DEMO-C-002', description: 'Concreto estrutural usinado (demonstrativo)', unit: 'm³',
    inputs: [
      { id: 'i5', description: 'Concreto usinado demonstrativo', type: 'material', unit: 'm³', coefficient: 1.05, unitPrice: 395 },
      { id: 'i6', description: 'Bomba de concreto (demonstrativo)', type: 'equipment', unit: 'h', coefficient: 0.12, unitPrice: 310 },
      { id: 'i7', description: 'Operário de concretagem (demonstrativo)', type: 'labor', unit: 'h', coefficient: 1.6, unitPrice: 21 },
    ],
  },
  {
    id: 'cp3', code: 'DEMO-C-003', description: 'Pintura acrílica em parede interna (demonstrativo)', unit: 'm²',
    inputs: [
      { id: 'i8', description: 'Tinta acrílica demonstrativa', type: 'material', unit: 'l', coefficient: 0.32, unitPrice: 28 },
      { id: 'i9', description: 'Pintor (demonstrativo)', type: 'labor', unit: 'h', coefficient: 0.35, unitPrice: 22 },
    ],
  },
]

export const compositionCost = (c: Composition) => c.inputs.reduce((sum, i) => sum + i.coefficient * i.unitPrice, 0)

export const initialBudgetGroups: BudgetGroup[] = [
  {
    id: 'g1', name: '01. Estrutura',
    items: [
      { id: 'it1', code: 'DEMO-C-002', description: 'Concreto estrutural usinado (demonstrativo)', type: 'service', unit: 'm³', quantity: 180, unitPrice: 0, compositionId: 'cp2' },
      { id: 'it2', code: 'DEMO-M-010', description: 'Aço CA-50 demonstrativo', type: 'material', unit: 'kg', quantity: 12_500, unitPrice: 8.9 },
    ],
  },
  {
    id: 'g2', name: '02. Vedações',
    items: [
      { id: 'it3', code: 'DEMO-C-001', description: 'Alvenaria de vedação em bloco cerâmico (demonstrativo)', type: 'service', unit: 'm²', quantity: 940, unitPrice: 0, compositionId: 'cp1' },
      { id: 'it4', code: 'DEMO-E-020', description: 'Betoneira demonstrativa (locação)', type: 'equipment', unit: 'mês', quantity: 6, unitPrice: 1_150 },
    ],
  },
  {
    id: 'g3', name: '03. Acabamentos',
    items: [
      { id: 'it5', code: 'DEMO-C-003', description: 'Pintura acrílica em parede interna (demonstrativo)', type: 'service', unit: 'm²', quantity: 2_100, unitPrice: 0, compositionId: 'cp3' },
      { id: 'it6', code: 'DEMO-L-030', description: 'Equipe de acabamento (demonstrativo)', type: 'labor', unit: 'h', quantity: 1_800, unitPrice: 26 },
    ],
  },
]

export const purchaseRequests: PurchaseRequest[] = [
  { id: 'r1', number: 'SOL-0101', project: 'Residencial Modelo A', requester: 'Responsável Demo A', date: '2026-10-28', itemsCount: 6, priority: 'high', status: 'pending' },
  { id: 'r2', number: 'SOL-0102', project: 'Galpão Logístico Demo', requester: 'Responsável Demo B', date: '2026-10-30', itemsCount: 3, priority: 'urgent', status: 'approved' },
  { id: 'r3', number: 'SOL-0103', project: 'Edifício Comercial Demo', requester: 'Responsável Demo A', date: '2026-11-02', itemsCount: 12, priority: 'medium', status: 'quoting' },
  { id: 'r4', number: 'SOL-0104', project: 'Centro de Distribuição Demo', requester: 'Responsável Demo C', date: '2026-11-05', itemsCount: 2, priority: 'low', status: 'draft' },
  { id: 'r5', number: 'SOL-0105', project: 'Residencial Modelo A', requester: 'Responsável Demo B', date: '2026-11-09', itemsCount: 8, priority: 'medium', status: 'rejected' },
]

export const customers: Party[] = [
  { id: 'cu1', name: 'Cliente Demo 01', document: '00.000.000/0001-00', email: 'contato@cliente01.example', phone: '(00) 0000-0001', city: 'Cidade Demo', status: 'active' },
  { id: 'cu2', name: 'Cliente Demo 02', document: '00.000.000/0002-00', email: 'contato@cliente02.example', phone: '(00) 0000-0002', city: 'Cidade Demo', status: 'active' },
  { id: 'cu3', name: 'Cliente Demo 03', document: '00.000.000/0003-00', email: 'contato@cliente03.example', phone: '(00) 0000-0003', city: 'Município Exemplo', status: 'inactive' },
  { id: 'cu4', name: 'Cliente Demo 04', document: '00.000.000/0004-00', email: 'contato@cliente04.example', phone: '(00) 0000-0004', city: 'Município Exemplo', status: 'active' },
]

export const suppliers: Party[] = [
  { id: 's1', name: 'Fornecedor Demo Materiais', document: '11.111.111/0001-00', email: 'vendas@fornecedor01.example', phone: '(00) 1111-0001', city: 'Cidade Demo', status: 'active' },
  { id: 's2', name: 'Fornecedor Demo Locações', document: '11.111.111/0002-00', email: 'vendas@fornecedor02.example', phone: '(00) 1111-0002', city: 'Município Exemplo', status: 'active' },
  { id: 's3', name: 'Fornecedor Demo Serviços', document: '11.111.111/0003-00', email: 'vendas@fornecedor03.example', phone: '(00) 1111-0003', city: 'Cidade Demo', status: 'inactive' },
]

export const dashboard: DashboardData = {
  kpis: { totalProjects: 7, inProgress: 3, completed: 1, delayed: 1, totalBudget: 58_330_000, realizedCost: 21_480_000, payable: 1_920_000, receivable: 3_410_000, pendingRequests: 4 },
  financialEvolution: [
    { month: 'Jun', planned: 2.1, realized: 1.9 }, { month: 'Jul', planned: 3.0, realized: 2.8 },
    { month: 'Ago', planned: 4.2, realized: 4.4 }, { month: 'Set', planned: 5.6, realized: 5.1 },
    { month: 'Out', planned: 7.1, realized: 6.9 }, { month: 'Nov', planned: 8.4, realized: 7.6 },
  ],
  budgetVsActual: [
    { project: 'Res. Modelo A', budget: 4.85, actual: 3.0 }, { project: 'Galpão Demo', budget: 3.2, actual: 2.4 },
    { project: 'Ed. Comercial', budget: 12.4, actual: 3.5 }, { project: 'Ponte Demo', budget: 8.9, actual: 4.1 },
  ],
  byStatus: [
    { name: 'Em andamento', value: 3 }, { name: 'Concluídas', value: 1 }, { name: 'Em atraso', value: 1 },
    { name: 'Planejamento', value: 1 }, { name: 'Pausadas', value: 1 },
  ],
  monthlyPerformance: [
    { month: 'Jun', progress: 4 }, { month: 'Jul', progress: 6 }, { month: 'Ago', progress: 5 },
    { month: 'Set', progress: 7 }, { month: 'Out', progress: 8 }, { month: 'Nov', progress: 6 },
  ],
  costDistribution: [
    { name: 'Materiais', value: 46 }, { name: 'Mão de obra', value: 28 }, { name: 'Equipamentos', value: 12 },
    { name: 'Serviços de terceiros', value: 9 }, { name: 'Outros', value: 5 },
  ],
  recentProjects: projects.slice(0, 5).map(({ id, code, name, progress, status }) => ({ id, code, name, progress, status })),
  pendingTasks: [
    { id: 't1', title: 'Aprovar orçamento ORC-0002', due: '2026-11-14' },
    { id: 't2', title: 'Revisar solicitação SOL-0101', due: '2026-11-15' },
    { id: 't3', title: 'Atualizar cronograma do Galpão Demo', due: '2026-11-18' },
  ],
  alerts: [
    { id: 'a1', tone: 'danger', text: 'Galpão Logístico Demo está com atraso de 18 dias no cronograma.' },
    { id: 'a2', tone: 'warning', text: 'Custo realizado do Res. Modelo A atingiu 85% do previsto para o período.' },
    { id: 'a3', tone: 'primary', text: '2 solicitações de compra aguardam aprovação.' },
  ],
  movements: [
    { id: 'm1', text: 'Solicitação SOL-0105 foi rejeitada.', date: '2026-11-09' },
    { id: 'm2', text: 'Orçamento ORC-0003 criado como rascunho.', date: '2026-11-07' },
    { id: 'm3', text: 'Obra OBR-006 alterada para “Pausada”.', date: '2026-11-04' },
  ],
}
