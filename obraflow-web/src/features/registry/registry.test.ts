import { describe, expect, it } from 'vitest'
import { filterNavigation, firstPath, navigation, type NavNode } from '@/app/navigation'
import { atLeast, transitions } from '@/features/projects/api'
import { formatDocument } from '@/utils/format'
import { clientPaged } from './paging'

describe('menu filtrado por permissão (T-03)', () => {
  const labels = (nodes: NavNode[]): string[] => nodes.flatMap((n) => [n.label, ...labels(n.children ?? [])])

  it('esconde itens sem permissão e grupos que ficam vazios', () => {
    const only = new Set(['companies.view'])
    const result = labels(filterNavigation(navigation, (p) => only.has(p)))
    expect(result).toContain('Empresas')
    expect(result).not.toContain('Clientes')
    expect(result).not.toContain('Obras')
    expect(result).not.toContain('Equipes') // subgrupo sem nenhum item visível some
    expect(result).not.toContain('Recursos')
  })

  it('member (D-P28) vê equipamentos e obras, mas não funcionários', () => {
    const member = new Set(['companies.view', 'customers.view', 'suppliers.view', 'units.view', 'documents.view', 'equipment.view', 'projects.view'])
    const result = labels(filterNavigation(navigation, (p) => member.has(p)))
    expect(result).toEqual(expect.arrayContaining(['Obras', 'Equipamentos', 'Grupos de equipamentos']))
    expect(result).not.toContain('Materiais') // sem resources.view, o catálogo não aparece
    expect(result).not.toContain('Funcionários')
    expect(result).not.toContain('Cargo/Função')
  })

  it('menu recolhido usa o primeiro caminho navegável do grupo', () => {
    const cadastros = navigation.find((n) => n.label === 'Cadastros')!
    expect(firstPath(cadastros)).toBe('/cadastros/empresas')
  })
})

describe('paginação no navegador para catálogos', () => {
  const rows = Array.from({ length: 23 }, (_, i) => ({ id: i + 1, name: `Item ${i + 1}`, status: i % 2 ? 'inactive' : 'active' }))
  const fetch = clientPaged(async () => rows, (r) => r.name, (r, p) => !p.status || r.status === p.status)

  it('pagina e informa o total', async () => {
    const page = await fetch({ page: 3, per_page: 10, q: '' })
    expect(page.data.map((r) => r.id)).toEqual([21, 22, 23])
    expect(page.meta).toEqual({ current_page: 3, last_page: 3, total: 23 })
  })

  it('busca sem diferenciar maiúsculas e aplica filtro', async () => {
    const page = await fetch({ page: 1, per_page: 10, q: 'item 1', status: 'active' })
    expect(page.data.map((r) => r.id)).toEqual([1, 11, 13, 15, 17, 19])
  })

  it('página além do fim volta para a última', async () => {
    const page = await fetch({ page: 99, per_page: 10, q: 'item 2' })
    expect(page.meta.current_page).toBe(1)
  })
})

describe('formatação de documentos', () => {
  it('formata CPF e CNPJ e preserva a máscara da API (D-P21)', () => {
    expect(formatDocument('52998224725')).toBe('529.982.247-25')
    expect(formatDocument('11222333000181')).toBe('11.222.333/0001-81')
    expect(formatDocument('***.982.247-**')).toBe('***.982.247-**')
    expect(formatDocument(null)).toBe('—')
  })
})

describe('obras', () => {
  it('estados finais não têm transição (espelho de D-P27)', () => {
    expect(transitions.completed).toEqual([])
    expect(transitions.canceled).toEqual([])
    expect(transitions.planning).toEqual(['in_progress', 'canceled'])
  })

  it('nível de acesso: view < edit < manage', () => {
    expect(atLeast('manage', 'edit')).toBe(true)
    expect(atLeast('view', 'edit')).toBe(false)
    expect(atLeast(null, 'view')).toBe(false)
  })
})
