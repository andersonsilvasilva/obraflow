# DESIGN_SYSTEM.md

Identidade oficial: **azul-safira** (decisão A-01). Fonte única dos tokens: [`obraflow-web/src/index.css`](../obraflow-web/src/index.css). Componentes **nunca** usam hexadecimal — apenas tokens semânticos (`bg-primary`, `text-muted-foreground`…).

## 1. Paleta oficial

| Token de marca | Hex | Papel |
|---|---|---|
| `--brand-sapphire` | `#0F52BA` | Principal: botões primários, links, ícones/estados ativos, seleção |
| `--brand-sapphire-hover` | `#0B4298` | Hover do principal |
| `--brand-deep` | `#0A2E66` | Contraste, textos sobre azul-suave |
| `--brand-soft` | `#EAF1FF` | Destaques, fundos de seleção/hover |
| `--brand-graphite` | `#252A35` | Texto e **sidebar** |
| `--brand-gray` | `#F3F4F6` | Fundo da aplicação |
| `--brand-white` | `#FFFFFF` | Cartões/superfícies |
| `--brand-success` | `#16A36A` | **Somente** estados positivos/confirmações |

Regras: sem laranja de destaque; sem gradientes excessivos; verde só para sucesso; cartões brancos com detalhes azuis; gráficos em tons de azul + complementares.

## 2. Tokens semânticos

`background, foreground, card, card-foreground, popover, primary, primary-hover, primary-foreground, primary-soft, primary-deep, secondary, muted, muted-foreground, accent, border, input, ring, success(+soft), warning(+soft), destructive(+soft), chart-1…5, sidebar-*`, além de `--radius`, `--shadow-card`, `--shadow-overlay`, `--sidebar-width`, `--sidebar-width-collapsed`, `--topbar-height`.

Para trocar a identidade basta editar os `--brand-*`.

**Contraste (calculado pela fórmula WCAG 2.x):**

| Par (texto / fundo) | Razão | AA texto (4,5) |
|---|---|---|
| Branco / `#0F52BA` (botão primário) | 7,15 | ✅ |
| Branco / `#0B4298` (hover) | 9,35 | ✅ |
| `#0F52BA` / branco e / `#F3F4F6` (links) | 7,15 · 6,50 | ✅ |
| `#252A35` / `#F3F4F6` (texto) | 13,06 | ✅ |
| `#5B6270` / branco · cinza (texto secundário) | 6,13 · 5,57 | ✅ |
| `#0A2E66` / `#EAF1FF` | 11,61 | ✅ |
| `#D5D9E2` / `#252A35` (sidebar) | 10,16 | ✅ |
| `#C53030` / `#FDEAEA` (erro) | 4,72 | ✅ |
| `#0B7A4F` (`success-strong`) / `#E6F6EF` | 4,81 | ✅ |
| `#8A5A00` (`warning-strong`) / `#FDF3E0` | 5,38 | ✅ |
| `#16A36A` (verde oficial) / branco | 3,24 | ❌ texto · ✅ ícones/preenchimentos (≥ 3:1) |
| `#16A36A` / `#E6F6EF` | 2,90 | ❌ **não usar para texto** |

Por isso o verde oficial `#16A36A` é usado apenas em preenchimentos e ícones; **texto** de sucesso/aviso usa os tons derivados `success-strong`/`warning-strong`. O botão `success` com texto branco (3,24) não deve ser usado para texto pequeno.

## 3. Tipografia

Geist Variable (sans). Títulos de página `text-2xl font-semibold`; seções `text-sm font-semibold`; corpo `text-sm`; dados numéricos com `tabular-nums`; rótulos auxiliares `text-xs text-muted-foreground`.

## 4. Espaçamento e forma

Escala Tailwind (4 px). Painéis `p-4`; barras de filtro `p-3`; raio `0.5rem`; sombra sutil `shadow-card` em cartões; sombra de overlay só em modais/toasts. Densidade alta em tabelas (`py-2.5`).

## 5. Componentes

| Grupo | Componentes | Local |
|---|---|---|
| Layout | `AppLayout`, `Sidebar`, `Topbar`, `Breadcrumb`, `PageHeader`, `Tabs` | `app/layouts`, `components/layout` |
| Indicadores | `StatCard`, `MetricCard`, `StatusBadge`, `ProgressIndicator`, `DemoBanner` | `components/data-display/indicators.tsx` |
| Formulários | `FormField`, `TextInput`, `SelectInput`, `TextArea`, `SearchInput`, `CurrencyInput` | `components/forms/fields.tsx` |
| Tabelas | `DataTable` (ordenação, paginação), `TablePagination`, `TableToolbar`, `Panel` | `components/data-display/DataTable.tsx` |
| Feedback | `Alert`, `Modal` (centro/lateral), `ConfirmDialog`, `Toast`, `EmptyState`, `LoadingState`, `ErrorState`, `ModuleInPreparation`, `ErrorBoundary`, `QueryBoundary` | `components/feedback`, `app/providers/ToastProvider` |
| Base shadcn | `Button` | `components/ui` |
| Orçamentos | `CostComposition` (painel lateral), editor de grupos/itens, ABC, gráficos | `features/budgets` |

### Pendentes do PROMPT 002 §5
`DatePicker` (usa `<input type="date">` nativo), `DropdownMenu` e `Pagination` como componente isolado (paginação existe em `TablePagination`), `TableFilters`/`TableActions` dedicados (filtros via `TableToolbar`+`FormField`), `BudgetSummary`/`BudgetGroup`/`BudgetActivity`/`ResourceTable`/`ABCCurve`/`BudgetCharts` como componentes isolados (hoje compostos dentro das páginas). Foram adiados para não criar abstrações sem uso repetido.

## 6. Estados

Toda tela de dados trata **carregando** (`LoadingState`), **vazio** (`EmptyState`), **erro** (`ErrorState` com retry) e **sucesso** (toast). Módulos não desenvolvidos mostram `ModuleInPreparation`, sem simular funcionalidade. Dados fictícios são sinalizados por `DemoBanner`.

## 7. Responsividade

Desktop-first. `lg+`: sidebar fixa (recolhível, preferência em `localStorage` com *try/catch*). `<lg`: sidebar vira gaveta (botão no Topbar). Seletores de empresa/obra aparecem em `lg`/`xl`. Tabelas rolam horizontalmente (`overflow-x-auto`, `min-w-max`) sem espremer colunas. Formulários passam de 2 colunas para 1.

## 8. Acessibilidade

Link "Ir para o conteúdo"; `nav` com `aria-label`; foco visível global (`:focus-visible`); `FormField` liga `label`/erro/dica via `htmlFor`, `aria-invalid`, `aria-describedby`; `Modal` sobre `<dialog>` (foco preso, ESC); `Tabs` com `role=tab` e setas; tabelas com `caption` (sr-only), `scope="col"` e `aria-sort`; `role="progressbar"`, `role="status"/"alert"`; ícones decorativos `aria-hidden`; gráficos com `role="img"` e `aria-label`. **Pendência:** alternativa tabular dos gráficos e teste com leitor de tela.
