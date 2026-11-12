# PROMPT 004 — MODELAGEM COMPLETA DO BANCO DE DADOS DO OBRAFLOW

Atue como Arquiteto de Dados Sênior, especialista em MySQL, Laravel, sistemas ERP e arquitetura SaaS multi-tenant.

Sua missão é projetar o modelo de dados do ObraFlow, plataforma SaaS de gestão da construção civil desenvolvida pela HighTech Tecnologia.

O objetivo é criar uma modelagem robusta, normalizada, escalável, segura e preparada para suportar todas as funcionalidades previstas no produto.

ATENÇÃO: Esta tarefa deverá priorizar a modelagem, documentação, relacionamentos, restrições e validação arquitetural. Não criar migrations definitivas antes da aprovação expressa do modelo.

# 1. CONTEXTO TECNOLÓGICO

Backend:
- PHP 8.4.
- Laravel.
- MySQL.
- Laravel Sanctum.
- Spatie Laravel Permission.
- API REST versionada.

Frontend:
- React.
- TypeScript.
- Vite.

Infraestrutura:
- Redis.
- Filas.
- Object Storage compatível com S3.
- Docker futuramente.

# 2. PRINCÍPIOS DA MODELAGEM

Adotar:

- Normalização adequada.
- Integridade referencial.
- Chaves primárias e estrangeiras.
- Índices planejados.
- Restrições de unicidade.
- Auditoria.
- Rastreabilidade.
- Soft deletes quando apropriado.
- Precisão decimal para valores financeiros.
- Padronização de nomenclatura.
- Controle de datas e horários.
- Suporte a crescimento do volume de dados.

Utilizar nomes de tabelas e colunas em inglês, seguindo convenções Laravel.

Utilizar `BIGINT UNSIGNED` para identificadores quando adequado.

Utilizar `DECIMAL` para valores monetários, quantidades e coeficientes que exigem precisão.

Não utilizar FLOAT ou DOUBLE para valores financeiros.

Todos os registros operacionais deverão possuir contexto de tenant.

# 3. ARQUITETURA MULTI-TENANT — REGRA FUNDAMENTAL

O ObraFlow utilizará arquitetura multi-tenant com banco de dados compartilhado e isolamento lógico por organização.

Cada tenant representa uma organização contratante do SaaS.

## 3.1 Identidade global dos usuários

A tabela `users` será global.

REGRA OBRIGATÓRIA:

A tabela `users` NÃO deverá possuir `tenant_id`.

Um mesmo usuário poderá trabalhar para diferentes construtoras, utilizando o mesmo login.

A relação entre usuários e organizações deverá ocorrer por:

`tenant_users`

Campos conceituais:

- id
- tenant_id
- user_id
- membership_status
- joined_at
- created_at
- updated_at

Criar restrição UNIQUE para impedir duplicidade de associação entre tenant e usuário.

## 3.2 Tenants

Criar tabela `tenants`.

Campos conceituais:

- id
- name
- slug
- legal_name
- document_number
- email
- phone
- status
- timezone
- locale
- created_at
- updated_at
- deleted_at

Avaliar quais campos são obrigatórios e quais devem ser opcionais.

O tenant representa a conta organizacional do SaaS, não necessariamente uma única pessoa jurídica.

## 3.3 Empresas e filiais

Criar estrutura para permitir que um tenant possua várias empresas e filiais.

Sugestão:

- companies
- company_branches

A empresa deverá possuir identificação jurídica, endereço, dados cadastrais e situação.

As filiais deverão pertencer à empresa e ao tenant correspondente.

## 3.4 Associação de usuários às empresas

Criar:

`user_companies`

Permitir que um usuário associado ao tenant tenha acesso a uma ou mais empresas daquele tenant.

## 3.5 Associação de usuários às obras

Criar:

`user_project_access`

Permitir controlar quais obras um usuário pode acessar.

O acesso à obra deverá respeitar:

- Membership ativo no tenant.
- Associação à empresa, quando aplicável.
- Permissões do usuário.
- Acesso específico à obra.

## 3.6 Permissões

Utilizar Spatie Laravel Permission com Teams habilitado.

O contexto de permissão deverá considerar o tenant ativo.

Documentar a configuração de `team_foreign_key` e o procedimento para definir o contexto do tenant antes de verificar permissões.

Não implementar um sistema paralelo de permissões sem justificativa.

# 4. ISOLAMENTO E INTEGRIDADE REFERENCIAL

Este é um requisito crítico.

Projetar as relações para impedir que um registro de um tenant seja associado acidentalmente a outro tenant.

Exemplo:

Uma obra do tenant A não poderá receber contrato, orçamento, documento, medição ou lançamento financeiro pertencente ao tenant B.

Sempre que adequado, utilizar chaves estrangeiras compostas.

Exemplo conceitual:

- projects: UNIQUE (tenant_id, id)
- budgets: FOREIGN KEY (tenant_id, project_id) REFERENCES projects (tenant_id, id)

Avaliar cuidadosamente a compatibilidade com migrations e Eloquent.

Documentar como serão implementadas as validações de tenant no Laravel.

Não confiar exclusivamente em filtros enviados pelo frontend.

O isolamento deverá ser garantido no backend.

# 5. DOMÍNIOS FUNCIONAIS

Projetar o banco de dados considerando os seguintes domínios.

## 5.1 Administração SaaS

Modelar:

- tenants
- tenant_users
- plans
- plan_features
- subscriptions
- subscription_items, se necessário
- subscription_usage
- invoices
- payments
- tenant_settings

Prever controle de:

- Planos.
- Assinaturas.
- Limites.
- Recursos contratados.
- Situação da assinatura.
- Histórico de alterações.

Não fixar valores comerciais definitivos.

## 5.2 Usuários e segurança

Modelar:

- users
- tenant_users
- user_companies
- user_project_access
- roles
- permissions
- model_has_roles
- model_has_permissions
- role_has_permissions

Considerar as tabelas gerenciadas pelo Spatie Permission.

Avaliar:

- Autenticação.
- Tokens Sanctum.
- MFA futuro.
- Auditoria.
- Sessões.
- Convites.
- Recuperação de acesso.

Evitar duplicar tabelas já oferecidas pelos pacotes adotados.

## 5.3 Cadastro empresarial

Modelar:

- companies
- company_branches
- customers
- suppliers
- contacts
- addresses

Permitir múltiplos contatos e endereços quando necessário.

## 5.4 Obras e projetos

Modelar:

- projects
- project_members
- project_settings
- project_phases
- project_status_history
- project_documents

Cada obra deverá possuir:

- Código.
- Nome.
- Descrição.
- Cliente.
- Empresa responsável.
- Responsável técnico.
- Endereço.
- Datas.
- Valor contratado.
- Situação.
- Progresso.

## 5.5 Contratos

Modelar:

- contracts
- contract_items
- contract_addenda
- contract_documents
- contract_parties
- contract_status_history

Prever contratos vinculados a clientes, fornecedores, empresas e obras, conforme a regra de negócio.

## 5.6 Orçamentos

Este será um dos domínios mais importantes do ObraFlow.

Modelar:

- budgets
- budget_versions
- budget_groups
- budget_items
- budget_compositions
- budget_composition_items
- budget_resources
- budget_resource_items
- budget_costs
- budget_indirect_costs
- budget_bdi
- budget_approvals
- budget_status_history
- budget_imports

A modelagem deverá permitir:

- Orçamento por cliente.
- Orçamento por obra.
- Orçamento manual.
- Orçamento baseado em referências técnicas.
- Múltiplas versões.
- Histórico de revisões.
- Composições de custos.
- Serviços.
- Insumos.
- Materiais.
- Mão de obra.
- Equipamentos.
- Quantidades.
- Unidades.
- Coeficientes.
- Preços unitários.
- Custos totais.
- BDI.
- Encargos.
- Aprovação.
- Curva ABC.

Preservar histórico de preços e composições utilizados em cada orçamento.

Uma alteração futura na composição de referência não poderá modificar retroativamente um orçamento já aprovado.

## 5.7 Bases de referência técnica

Preparar arquitetura para tabelas de referência como SINAPI e SUDECAP.

Modelar:

- reference_sources
- reference_datasets
- reference_competencies
- reference_locations
- reference_compositions
- reference_composition_items
- reference_resources
- reference_resource_prices

Considerar:

- Fonte.
- Competência.
- UF.
- Município ou localidade, quando aplicável.
- Regime de encargos.
- Unidade.
- Código.
- Descrição.
- Valor.
- Versão da base.

Não inventar valores de referência.

Distinguir claramente preços oficiais importados, preços personalizados e valores negociados.

## 5.8 Planejamento e cronograma

Modelar:

- schedules
- schedule_tasks
- schedule_dependencies
- schedule_milestones
- schedule_baselines
- schedule_progress_history

Permitir:

- Estrutura analítica do projeto.
- Etapas.
- Atividades.
- Dependências.
- Datas planejadas.
- Datas realizadas.
- Percentual concluído.
- Marcos.
- Linha de base.

## 5.9 Diário de Obras

Modelar:

- construction_diaries
- construction_diary_activities
- construction_diary_occurrences
- construction_diary_teams
- construction_diary_equipment
- construction_diary_weather
- construction_diary_attachments

Registrar:

- Data.
- Condições climáticas.
- Equipes.
- Atividades.
- Equipamentos.
- Ocorrências.
- Interferências.
- Fotos.
- Responsáveis.
- Aprovações.

## 5.10 Medições

Modelar:

- measurements
- measurement_items
- measurement_attachments
- measurement_approvals
- measurement_history

Permitir medições contratuais e físicas, conforme a natureza da obra.

Preservar histórico de aprovação e alterações.

## 5.11 Financeiro

Modelar:

- financial_accounts
- financial_categories
- accounts_payable
- accounts_receivable
- financial_transactions
- financial_transaction_items
- cost_centers
- cash_flow_entries
- payment_methods
- financial_reconciliations

Permitir associação com:

- Tenant.
- Empresa.
- Obra.
- Contrato.
- Fornecedor.
- Cliente.
- Categoria.

Considerar:

- Parcelas.
- Vencimentos.
- Pagamentos.
- Recebimentos.
- Juros.
- Multas.
- Descontos.
- Estornos.
- Conciliação.
- Fluxo de caixa.

Avaliar separação entre títulos financeiros e movimentações efetivamente realizadas.

## 5.12 Compras e suprimentos

Modelar:

- purchase_requests
- purchase_request_items
- purchase_orders
- purchase_order_items
- purchase_approvals
- supplier_quotes
- supplier_quote_items
- goods_receipts
- goods_receipt_items

Permitir fluxo:

Solicitação → Aprovação → Cotação → Pedido de compra → Recebimento.

## 5.13 Estoque

Modelar:

- warehouses
- warehouse_locations
- inventory_items
- inventory_balances
- inventory_movements
- inventory_movement_items
- stock_reservations
- stock_adjustments

Permitir controle por empresa, depósito e obra, conforme a regra de negócio.

Evitar armazenar saldo como informação sem histórico de movimentações.

## 5.14 Equipes e recursos humanos operacionais

Modelar:

- employees
- employee_documents
- employee_certifications
- labor_allocations
- work_shifts
- attendance_records
- equipment
- equipment_allocations
- equipment_maintenance

Considerar que funcionários operacionais podem existir independentemente dos usuários que acessam o sistema.

Não confundir empregado cadastrado com usuário autenticável.

## 5.15 Documentos

Modelar:

- documents
- document_versions
- document_categories
- document_links
- document_permissions

Prever associação de documentos a obras, contratos, empresas, clientes e demais entidades.

Armazenar arquivos em Object Storage, não diretamente como BLOB no MySQL, salvo justificativa específica.

Guardar metadados, localização lógica, hash, tamanho, tipo MIME e versão.

## 5.16 Relatórios e auditoria

Modelar:

- audit_logs
- notifications
- notification_preferences
- user_activity_logs, se necessário
- integration_logs

Registrar eventos importantes:

- Criação.
- Alteração.
- Exclusão.
- Aprovação.
- Rejeição.
- Alteração financeira.
- Alteração de permissões.
- Mudança de situação.

Não registrar senhas, tokens ou dados secretos nos logs.

## 5.17 Inteligência artificial

Preparar estrutura para:

- ai_conversations
- ai_messages
- ai_usage_logs
- ai_generated_suggestions
- ai_feedback

As sugestões geradas por IA deverão possuir origem, usuário, tenant, data e situação de aprovação, quando aplicável.

Não permitir que sugestões de IA sejam tratadas automaticamente como decisões financeiras ou técnicas aprovadas.

# 6. REGRAS DE MODELAGEM FINANCEIRA

Utilizar DECIMAL com precisão adequada.

Avaliar como padrão inicial:

- Valores monetários: DECIMAL(15,2).
- Coeficientes: DECIMAL(18,6).
- Quantidades: DECIMAL(18,4).
- Percentuais: DECIMAL(9,4).

Justificar ajustes necessários.

Documentar arredondamento e precisão dos cálculos.

Avaliar suporte a múltiplas moedas para evolução futura, sem implementar complexidade desnecessária no MVP.

# 7. PADRÕES DE BANCO DE DADOS

Adotar:

- InnoDB.
- UTF8MB4.
- Collation compatível com a versão escolhida do MySQL.
- Índices para consultas frequentes.
- FKs explícitas.
- Constraints de unicidade.
- Datas armazenadas em UTC quando aplicável.
- Conversão para timezone do tenant na apresentação.

Documentar o comportamento de exclusões em cascata.

Não utilizar exclusão em cascata indiscriminadamente em registros financeiros, contratuais ou históricos.

# 8. MODELAGEM DE AUDITORIA E HISTÓRICO

Diferenciar:

- Dados atuais.
- Histórico de alterações.
- Versões de documentos.
- Versões de orçamento.
- Eventos de auditoria.

Não utilizar apenas `updated_at` como mecanismo de histórico.

Definir quais entidades exigem rastreabilidade completa.

# 9. ENTREGÁVEIS OBRIGATÓRIOS

Criar os documentos:

`documentation/DATABASE.md`

Deverá conter:

- Modelo lógico.
- Domínios.
- Tabelas.
- Colunas.
- Tipos.
- Chaves.
- Índices.
- Relacionamentos.
- Restrições.

`documentation/ERD.md`

Deverá conter diagramas Mermaid separados por domínio e um diagrama geral simplificado.

`documentation/DATA_DICTIONARY.md`

Deverá documentar as tabelas e os campos.

`documentation/MULTITENANCY.md`

Deverá explicar o isolamento, as associações e os mecanismos de segurança.

`documentation/DB_CONSTRAINTS.md`

Deverá documentar integridade referencial, unicidade e regras de exclusão.

`documentation/DB_MIGRATION_PLAN.md`

Deverá apresentar a ordem proposta de criação das migrations e dependências.

`documentation/DB_DECISIONS.md`

Deverá registrar as decisões arquiteturais, justificativas e alternativas avaliadas.

Atualizar DECISIONS.md e ARCHITECTURE.md, quando necessário.

# 10. REQUISITOS PARA OS DIAGRAMAS

Criar diagramas Mermaid legíveis.

Separar por domínios:

1. SaaS e multi-tenancy.
2. Usuários e permissões.
3. Empresas e obras.
4. Contratos.
5. Orçamentos e composições.
6. Referências técnicas.
7. Planejamento e diário de obras.
8. Medições.
9. Financeiro.
10. Compras e estoque.
11. Equipes e equipamentos.
12. Documentos e auditoria.
13. Inteligência artificial.

Não produzir um único diagrama gigantesco e ilegível.

# 11. REVISÃO CRÍTICA OBRIGATÓRIA

Antes de considerar a modelagem concluída, revisar:

- Todas as tabelas operacionais possuem tenant_id quando necessário?
- Existem relações que permitem cruzamento entre tenants?
- Existem chaves estrangeiras sem índice adequado?
- Existem campos duplicados desnecessariamente?
- Existem relacionamentos muitos-para-muitos sem tabela pivô?
- Os valores financeiros usam DECIMAL?
- Os orçamentos preservam versões?
- As composições preservam os preços de referência utilizados?
- As movimentações de estoque possuem histórico?
- As movimentações financeiras são auditáveis?
- As aprovações possuem histórico?
- Os usuários globais podem participar de vários tenants?
- As permissões respeitam o tenant ativo?
- Os documentos possuem controle de acesso?
- As exclusões podem comprometer históricos importantes?

Corrigir inconsistências e documentar as decisões.

# 12. LIMITES DE EXECUÇÃO

Nesta etapa:

1. Não criar migrations definitivas.
2. Não executar migrations.
3. Não criar tabelas diretamente no MySQL.
4. Não modificar o banco de dados do Laragon.
5. Não instalar pacotes desnecessários.
6. Não alterar a identidade global dos usuários.
7. Não remover tenant_id das entidades operacionais que precisam de isolamento.
8. Não inventar regras de negócio sem documentar a hipótese.
9. Não implementar módulos funcionais.
10. Não iniciar integração com frontend.

Se encontrar decisões ainda não definidas, apresentar alternativas com vantagens, desvantagens e recomendação.

# 13. CRITÉRIOS DE ACEITE

A modelagem será considerada pronta para aprovação quando:

- Todos os domínios previstos estiverem representados.
- O modelo multi-tenant estiver documentado.
- A identidade global dos usuários estiver preservada.
- Os relacionamentos estiverem definidos.
- As regras de integridade estiverem documentadas.
- Os diagramas Mermaid estiverem organizados.
- O dicionário de dados estiver criado.
- O plano de migrations estiver documentado.
- As decisões pendentes estiverem identificadas.
- A revisão crítica estiver concluída.

# 14. RELATÓRIO FINAL

Apresentar:

1. Quantidade de domínios modelados.
2. Quantidade de tabelas propostas.
3. Principais relacionamentos.
4. Estratégia de isolamento multi-tenant.
5. Decisões financeiras.
6. Estratégia de auditoria.
7. Diagramas criados.
8. Restrições e índices.
9. Decisões pendentes.
10. Riscos identificados.
11. Próximos passos.

IMPORTANTE:

A modelagem deverá ser apresentada para revisão humana.

Aguarde aprovação expressa antes de criar migrations definitivas ou modificar qualquer banco de dados.

O objetivo é construir uma base de dados profissional, segura, escalável e sustentável para o ObraFlow.