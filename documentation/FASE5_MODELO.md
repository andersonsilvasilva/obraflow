# FASE 5 — CONTRATOS E MEDIÇÕES: MODELO APROVADO

> **Ajuste A-45 (2026-11-17):** o contrato pertence sempre a uma obra e só é acessado pela obra (escolha do responsável após ver o Brickup); o modelo completo (itens, aditivos, medições) foi mantido. O calendário e os horários da obra ([CALENDARIO_OBRA_MODELO.md](CALENDARIO_OBRA_MODELO.md)) vêm antes da 5A.
>
> **Aprovado em 2026-11-17.** Fase autorizada pelo responsável ("pode começar a nova fase"); decisões D-P44 a D-P56 aprovadas conforme a recomendação. A parte 5A (Contratos) está autorizada; a 5B (Medições) só começa após a homologação da 5A (D-P44).
> **Base:**
> - modelagem original ([DATABASE.md](DATABASE.md) §2.5 e §2.10; [ERD.md](ERD.md) §4);
> - regras já aprovadas: nada de contrato ou medição é excluído, só cancelado ([DB_DECISIONS.md](DB_DECISIONS.md), [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md)); orçamento aprovado é imutável (Fase 4B); acesso por obra (D-P24); precisão (D-P32).
>
> Esta proposta **atualiza** a modelagem original para aproveitar o que as Fases 3 e 4 entregaram. As diferenças estão marcadas com "muda".

## 1. Objetivo

Registrar os contratos da empresa, ligados à obra e, quando houver, ao orçamento aprovado. Controlar os aditivos (valor, prazo e escopo) e medir o que foi executado em cada período, com aprovação. O resultado é saber, por contrato:
- **valor contratado**, original e atual (com aditivos);
- **quanto já foi medido e aprovado** (em quantidade, em R$ e em %);
- **saldo a medir**.

Os títulos a receber ou a pagar gerados pelas medições ficam para o Financeiro (Fase 8).

## 2. Divisão em duas partes (como na Fase 4)

| Parte | Entrega | Homologação |
|---|---|---|
| **5A — Contratos** | Contratos, itens, aditivos, ciclo de vida, documentos e telas | própria |
| **5B — Medições** | Medições por período, boletim de medição, aprovação e telas | própria; só começa após a 5A |

## 3. Tabelas propostas

### 3.1 Parte 5A — Contratos (5 tabelas + 1 ajuste)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 1 | `contracts` | `uuid`, `code` (CTR-NNN/AAAA, D-P49), `kind` (`customer` = a empresa é contratada pelo cliente; `supplier` = a empresa contrata um fornecedor ou empreiteiro), `company_id`, `project_id` (**obrigatória**, A-45), `customer_id` / `supplier_id` (conforme o tipo), `budget_version_id` (opcional: versão aprovada de origem), `object` (texto), `original_value`, `current_value`, `starts_at`, `original_end_date`, `current_end_date`, `signed_at`, `retention_percent` (D-P54), `readjustment_rule` (texto), `status`, `notes`, `created_by` | FKs compostas (tenant); CHECK do tipo × parte (cliente **ou** fornecedor, nunca os dois); obra da mesma empresa; versão de orçamento **aprovada** e da mesma obra |
| 2 | `contract_items` | `contract_id`, `code`, `description`, `unit_code`, `quantity`, `unit_price`, `total`, `budget_item_id` (opcional), `addendum_id` (preenchido quando o item veio de aditivo), `sort_order` | quantidade > 0; total = quantidade × preço unitário, arredondado (D-P32) |
| 3 | `contract_addenda` | `contract_id`, `number` (1, 2, 3…), `kind` (`value`, `term`, `scope`, `mixed`), `value_delta`, `new_end_date`, `description`, `signed_at`, `status` (`draft`, `approved`, `canceled`) | só contrato **ativo** recebe aditivo; aprovado é imutável |
| 4 | `contract_addendum_items` | `addendum_id`, `contract_item_id` (alteração de quantidade) **ou** item novo (código, descrição, unidade, preço), `quantity_delta` | quantidade final de um item nunca fica abaixo do já medido (5B) |
| 5 | `contract_status_history` | `contract_id`, `from_status`, `to_status`, `reason`, `changed_by`, `changed_at` | só inserção (append-only) |
| ± | `document_links` | `linkable_type` passa a aceitar `contract` e `measurement` | migration de ajuste (D-P55) |

**Muda em relação à modelagem original:**
- sai a tabela polimórfica `contract_parties`: as partes viram FKs diretas, conferidas pelo banco (D-P46);
- sai `contract_documents`: os anexos usam os documentos da Fase 2 (D-P55);
- entra `contract_addendum_items` (aditivo de escopo, D-P48).

### 3.2 Parte 5B — Medições (4 tabelas)

| # | Tabela | Colunas principais | Regras |
|---|---|---|---|
| 6 | `measurements` | `uuid`, `contract_id`, `number` (1, 2, 3… por contrato), `period_start`, `period_end`, `gross_value`, `retention_value`, `net_value`, `status` (`draft`, `submitted`, `approved`, `rejected`, `canceled`), `submitted_by`/`at`, `notes` | número único por contrato; períodos sem sobreposição; só **uma** medição aberta por contrato (D-P52) |
| 7 | `measurement_items` | `measurement_id`, `contract_item_id`, `quantity_period`, `quantity_previous` (acumulado aprovado antes), `unit_price` (cópia), `value` | acumulado ≤ quantidade contratada atual (D-P53) |
| 8 | `measurement_approvals` | `measurement_id`, `decision` (`approved`, `rejected`), `comment`, `totals_hash`, `decided_by`, `decided_at` | só inserção; o hash protege os totais aprovados (como no orçamento) |
| 9 | `measurement_status_history` | `measurement_id`, `from_status`, `to_status`, `reason`, `changed_by`, `changed_at` | só inserção |

**Muda em relação à modelagem original:**
- a medição exige contrato (D-P51): o avanço físico sem contrato fica para o Planejamento (Fase 6);
- `measurement_history` (JSON genérico) dá lugar ao histórico de status mais a auditoria que já existe;
- `measurement_attachments` dá lugar aos documentos (D-P55).

**Total:** 9 tabelas novas e 1 ajuste. Nenhuma tem exclusão lógica: só status e cancelamento.

## 4. Decisões para aprovação

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P44** | Dividir a fase em **5A (Contratos)** e **5B (Medições)**, com homologações separadas? | **Sim.** Medição depende do contrato pronto e testado |
| **D-P45** | **Tipos de contrato**: a modelagem original tinha `customer`, `supplier` e `service` | **Dois tipos:** **com cliente** (receita: a empresa executa a obra) e **com fornecedor/empreiteiro** (despesa: a empresa contrata). "Serviço" é um contrato com fornecedor; o objeto descreve o serviço |
| **D-P46** | **Partes do contrato**: tabela polimórfica (`contract_parties`) ou FKs diretas? | **FKs diretas** (empresa + cliente **ou** fornecedor), conferidas pelo banco. A tabela polimórfica era um risco de isolamento sem FK ([DB_CONSTRAINTS.md](DB_CONSTRAINTS.md) §2). Testemunhas e fiadores ficam nas observações ou nos documentos |
| **D-P47** | **Itens do contrato a partir do orçamento** | Duas formas: (a) **gerar do orçamento aprovado**: copia os itens da versão aprovada com o **preço de venda** (com BDI e descontos), mantendo o vínculo `budget_item_id`. Como a versão aprovada não muda, o vínculo é seguro. (b) **Itens digitados**, para contratos sem orçamento (ex.: com fornecedor). Preço unitário com 2 casas (half-up); se o total do contrato diferir do preço final do orçamento por arredondamento, a tela mostra a diferença |
| **D-P48** | **Aditivos** | Tipos **valor**, **prazo**, **escopo** e **misto**. O de escopo inclui itens novos e altera quantidades de itens existentes. **Não** altera o preço de itens existentes (isso seria reajuste, D-P50). Ao ser aprovado: o valor atual = original + soma dos aditivos aprovados, e o prazo atual = o do último aditivo de prazo. Acima de 25% do valor original a tela **avisa** (limite da Lei 14.133 para contratos públicos), mas **não bloqueia** |
| **D-P49** | **Código do contrato** | **CTR-NNN/AAAA**, sequência por tenant e ano da data de início, gerado automaticamente como o da obra e o do orçamento. Aditivo e medição são numerados por contrato (Aditivo 1, Medição 1…) |
| **D-P50** | **Reajuste** (índice INCC etc.) | **Fora desta fase.** Guardar só a regra em texto (`readjustment_rule`). O cálculo de reajuste fica para uma etapa futura |
| **D-P51** | **Medição sem contrato** (avanço físico da obra) | **Não nesta fase:** toda medição pertence a um contrato. O avanço físico entra no Planejamento (Fase 6) |
| **D-P52** | **Uma medição aberta por contrato** e períodos sequenciais | **Sim.** A próxima medição só começa depois que a anterior foi aprovada ou cancelada. Assim o "acumulado anterior" é sempre conhecido e fixo |
| **D-P53** | **Medir acima do contratado** | **Bloqueia:** o acumulado de cada item não passa da quantidade contratada atual (com aditivos). Para medir mais, faz-se um aditivo. **Correção** de medição já aprovada: quantidade negativa na medição seguinte (estorno), sem deixar o acumulado negativo |
| **D-P54** | **Retenção contratual** (caução, ex.: 5%) | **Sim, opcional por contrato:** a medição calcula bruto, retenção e líquido. Impostos (ISS, INSS) ficam para o Financeiro (Fase 8) |
| **D-P55** | **Anexos** (contrato assinado, boletim, fotos) | **Reaproveitar os documentos da Fase 2** (disco privado + link temporário), com vínculos `contract` e `measurement` em `document_links`. Sem tabelas novas de anexo |
| **D-P56** | **Acesso e aprovação** | Contrato com obra segue o **acesso por obra** (D-P24), como o orçamento; contrato sem obra exige só a permissão. A aprovação de medição usa uma permissão própria. Recomendo **separar funções: quem enviou a medição não aprova** (é um valor que vira cobrança ou pagamento). O administrador da organização pode liberar a autoaprovação no futuro |

## 5. Ciclos de vida

**Contrato:**
- rascunho → ativo (exige data de assinatura) → concluído;
- ativo ↔ suspenso (com motivo);
- rascunho ou ativo → cancelado (com motivo; contrato com medição aprovada não pode ser cancelado, só concluído).

Com o contrato ativo, os itens ficam travados: qualquer mudança é por aditivo. Nada é excluído.

**Medição:**
- rascunho → enviada → aprovada ou rejeitada;
- rejeitada → rascunho (corrigir e reenviar);
- rascunho → cancelada.

A medição aprovada é imutável, guarda o hash dos totais e alimenta o acumulado. Só se mede contrato **ativo**.

## 6. Permissões (5 novas → 38)

| Permissão | Uso | `member` de tenants novos |
|---|---|---|
| `contracts.view` / `contracts.manage` | consultar / criar, editar, ativar, aditar, encerrar | `contracts.view` |
| `measurements.view` / `measurements.manage` / `measurements.approve` | consultar / lançar e enviar / aprovar ou rejeitar | `measurements.view` |

Na HighTech, as permissões só entram com autorização, como nas fases anteriores.

## 7. Telas (no padrão de layout já homologado)

- **Onde ficam (A-45).** O contrato **pertence sempre a uma obra**, como no Brickup (obra → Contratos, com as colunas Contrato, Descrição, Valor, Observações e Arquivos). É criado e consultado na **aba "Contratos" da obra**. Não há menu geral de contratos; a lista da aba mostra código, objeto, parte, valor atual, % medido, status e arquivos.
- **Contrato (detalhe)**, com abas:
  - **Resumo**: original × atual, medido, saldo, prazos;
  - **Itens**: com "Gerar do orçamento aprovado";
  - **Aditivos**;
  - **Medições** (5B);
  - **Documentos**;
  - **Histórico**.
- **Medição (boletim)**: tabela por item com contratado, acumulado anterior, **este período** (editável), acumulado, % executado e valor. Rodapé com bruto, retenção e líquido. Ações enviar, aprovar e rejeitar, conforme o status e as permissões.

As telas de contrato e medição do Brickup estão bloqueadas no plano atual do responsável, então não há referência visual dos campos. As telas seguem o padrão de layout já homologado ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md)).

## 8. Etapas (após aprovação)

**5A — Contratos**
1. Tabelas, modelos, regras, ciclo de vida, geração a partir do orçamento e API.
2. Aditivos (valor, prazo, escopo) e documentos.
3. Telas (lista, detalhe com abas, aditivos, aba na obra).
4. Testes, CI, migrations e permissões no `obraflow` (com autorização), servidores para teste e **homologação da 5A**.

**5B — Medições** (só após a homologação da 5A)

5. Tabelas, regras (acumulado, limites, retenção, estorno) e API.
6. Fluxo de aprovação com hash e separação de funções.
7. Telas (boletim de medição, aba no contrato).
8. Testes, CI, banco (com autorização) e **homologação da 5B**.

## 9. Riscos

- **Arredondamento orçamento → contrato:** o preço de venda unitário com 2 casas pode gerar centavos de diferença no total. A diferença é mostrada e não é escondida (D-P47).
- **Regras legais:** o aviso de 25% vale para contratos públicos; contratos privados seguem o que foi assinado. O sistema não substitui a análise jurídica.
- **Separação de funções (D-P56):** numa organização com um só usuário que aprova, a medição não pode ser aprovada até que outra pessoa tenha `measurements.approve`.
- **Financeiro:** a medição aprovada ainda não gera título. A integração fica para a Fase 8.

## 10. Andamento

### 5A — etapas 1 e 2 (backend)

- **Migrations** `2026_11_17_400001` a `400006`:
  - tabelas `contracts`, `contract_addenda`, `contract_items`, `contract_addendum_items` e `contract_status_history`;
  - ajuste em `document_links` para aceitar `contract` e `contract_addendum` (o arquivo assinado de cada aditivo fica no próprio aditivo).
  - **Regras do banco:**
    - parte do contrato × tipo;
    - retenção abaixo de 100%;
    - contrato ativo, suspenso ou concluído exige assinatura;
    - aditivo aprovado exige assinatura, data e aprovador; aditivo de prazo exige nova data ao ser aprovado;
    - linhas do aditivo coerentes com o tipo.
- **Regras na aplicação:**
  - contrato sempre de uma obra, com a empresa da obra;
  - código CTR-NNN/AAAA;
  - itens só em rascunho;
  - ativação exige assinatura e itens e fixa o valor original;
  - depois de ativo, só observações e regra de reajuste mudam fora dos aditivos.
- **Gerar do orçamento aprovado (D-P47):**
  - o preço de venda unitário leva o desconto global repartido, arredondado a 2 casas;
  - os custos indiretos viram o item "CI" (VB);
  - a diferença de centavos em relação ao preço final do orçamento volta na resposta.
- **Aditivos (D-P48):**
  - só contrato ativo recebe aditivo;
  - linhas de alteração de quantidade e de item novo;
  - o valor do aditivo é a diferença dos totais dos itens, então atual = original + Σ aditivos, exato;
  - o prazo atualiza o término atual;
  - a resposta avisa quando os acréscimos passam de 25%, sem bloquear;
  - aditivo aprovado é imutável; o rascunho pode ser cancelado.
- **Ciclo de vida:** ativar, suspender (com motivo), retomar, concluir e cancelar (com motivo). Aditivo em rascunho impede concluir ou cancelar. Nada se exclui (DELETE → 405).
- **Acesso (D-P56):** `contracts.view` e `contracts.manage` (35 no catálogo), mais o acesso por obra. Sem acesso à obra, a resposta é 404. O `member` de tenants novos recebe `contracts.view`.
- **Auditoria:** `contract.status_changed`, `contract.items_generated`, `contract.addendum_approved` e `contract.addendum_canceled`. O cadastro em si audita em `registry.*`.
- **Testes:** `Feature/Contracts/ContractApiTest` (8). Suíte 442/442.
