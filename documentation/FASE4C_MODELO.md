# FASE 4C — CATÁLOGO DE INSUMOS DA ORGANIZAÇÃO: PROPOSTA DE MODELO

> **Proposta — aguarda aprovação.** Pedido do responsável em 2026-11-17 ("cadastro de insumos próprio"). O item tinha sido adiado na D-P35. Nenhuma migration é criada antes da aprovação das decisões D-P37 a D-P43.
> **Base:**
> - telas de referência "Cadastro de Produtos" (Materiais), "Cadastro de Recursos" (Mão de obra) e "Balanço" ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md), itens 14, 16 e 17);
> - planilhas `product.xlsx` (Subgrupo, Grupo*, Nome*, Marca, Fornecedor principal, Unidade de medida*) e `equipment.xlsx`;
> - D-08 (orçamento autossuficiente) e D-P32 (precisão).

## 1. Objetivo

Hoje o orçamento só usa insumos e composições do SINAPI ou itens digitados à mão. A empresa precisa de um **catálogo próprio** com materiais, mão de obra, equipamentos e serviços, com **preço da empresa**, para:
- lançar itens no orçamento a partir do catálogo (cópia autossuficiente, como no SINAPI);
- manter preços negociados e itens que o SINAPI não tem.

Menu: **Cadastros → Recursos → Materiais · Mão de obra · Equipamentos · Serviços** (Cargo/Função e Grupos já existem).

## 2. Tabelas propostas (2 novas + ajustes)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 1 | `resources` (SD) — insumo da organização | `uuid`, `kind` (material, labor, equipment, service), `code` (opcional), `name`, `unit_id` (unidade do tenant), `group_id` (grupo/subgrupo do mesmo tipo), `brand`, `main_supplier_id`, `job_title_id` (mão de obra), `equipment_id` (equipamento da Fase 3, opcional), `price` DECIMAL(15,2), `price_date`, `notes`, `status` | código único entre os ativos do tenant; grupo do mesmo tipo (FK com `kind`) |
| 2 | `resource_labor_costs` — "balanço" da mão de obra (D-P39) | `resource_id`, `base_salary`, `extra_hour`, `charges_percent`, `admin_percent`, `monthly_hours`, `labor_type` (direct, indirect), `contract_type`, `effective_date` | histórico por data; o preço/hora vem da composição vigente |
| ± | `resource_groups` | `kind` passa a aceitar `labor` e `service` (hoje: `equipment`, `material`) | migration de ajuste |
| ± | orçamento | item com origem **`catalog`** e `source_ref` apontando para o insumo (cópia, sem FK — D-08) | ajuste do CHECK de `budget_items.source` |

## 3. Decisões para aprovação

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P37** | Catálogo por **organização** ou por **empresa** (como clientes e fornecedores, D-P17)? | **Por organização**: os mesmos materiais e preços servem a todas as empresas do grupo. Simplifica o uso no orçamento |
| **D-P38** | Equipamento do catálogo × equipamento da Fase 3 (patrimônio, sem custo) | **Separados, mas ligáveis**: o catálogo guarda o **custo** (hora ou dia) usado no orçamento; o da Fase 3 é o bem físico. Um insumo de equipamento pode apontar para um equipamento da Fase 3 (opcional) |
| **D-P39** | **Preço da mão de obra**: informado direto ou calculado pelo "balanço" (salário, encargos %, administração %, carga horária)? | **As duas formas.** Padrão é o preço/hora informado. Opcionalmente, o balanço calcula: preço/hora = salário base × (1 + encargos%) × (1 + administração%) ÷ carga horária mensal, arredondado (D-P32). Histórico por data de vigência. Não faz cálculo próprio de encargos por rubrica (coerente com D-P03) |
| **D-P40** | **Grupos**: reaproveitar `resource_groups` (com tipo) ou um cadastro novo? | **Reaproveitar** (D-P22), ampliando os tipos para mão de obra e serviço. As telas de Grupos ganham o filtro por tipo |
| **D-P41** | **Uso no orçamento** | Nova opção "**Do catálogo da empresa**" ao lado da base SINAPI. Copia nome, unidade e preço vigentes com origem `catalog` (D-08). Mudar o preço no catálogo **não** altera orçamentos já feitos |
| **D-P42** | **Composições próprias** (receitas da empresa, com coeficientes) | **Depois** (etapa própria): nesta etapa só insumos com preço. Composições próprias pedem tela de montagem e recálculo em cascata |
| **D-P43** | **Importação das planilhas** `product.xlsx` e `equipment.xlsx` | **Sim, por tela**, com envio de planilha, **pré-visualização** (linhas válidas e com erro) e confirmação. Grupo e subgrupo criados se não existirem; unidade e fornecedor localizados pelo nome. Nada é gravado sem confirmação. Não importa dados pessoais (a `employee.xlsx` fica fora) |

## 4. Permissões

`resources.view` e `resources.manage`. O `member` de tenants novos recebe `resources.view`. Na HighTech, as permissões só entram com autorização.

## 5. Etapas (após aprovação)

1. Tabelas, modelos, regras e API do catálogo (com balanço da mão de obra).
2. Telas: Materiais, Mão de obra (com balanço), Equipamentos (custo), Serviços, no padrão de listagem da tela "Cadastro de Produtos".
3. Uso no orçamento ("Do catálogo da empresa").
4. Importação de planilhas com pré-visualização.
5. Testes, CI, migrations e permissões no `obraflow` (com autorização), servidores para teste.

## 6. Riscos

- **Fórmula do balanço:** é simplificada (D-P39). Se a empresa usa outra metodologia de custo de mão de obra, a fórmula precisa ser ajustada antes do uso.
- **Importação:** grupos e unidades com nomes diferentes podem gerar duplicados. A pré-visualização mostra o que será criado.
- **Preço vigente:** o catálogo guarda o preço atual. Orçamentos antigos não mudam (D-08).
