# FASE 2 — CADASTROS: MODELO APROVADO

> **Aprovado em 2026-11-13** (recomendações D-P11…D-P18 aceitas pelo responsável). Implementação por etapas (§5); migrations só no `obraflow_testing` até autorização de `migrate` no `obraflow`. Base: [DATABASE.md](DATABASE.md) §2.3, §2.15 e `units_of_measure`; [DB_CONSTRAINTS.md](DB_CONSTRAINTS.md); padrões da Fase 1 ([FASE1_MODELO.md](FASE1_MODELO.md)).

## 1. Escopo (ROADMAP)

Unidades de medida, empresas, filiais, clientes, fornecedores, contatos, endereços e documentos. **Fora:** funcionários, equipamentos, obras (Fase 3), orçamentos (Fase 4), financeiro.

## 2. Tabelas (18, após D-P12)

> **D-P12 aprovada:** `contacts`/`addresses` polimórficas foram **substituídas** por tabelas dedicadas com FK composta real: `company_addresses`, `company_contacts`, `company_branch_addresses`, `company_branch_contacts`, `customer_addresses`, `customer_contacts`, `supplier_addresses`, `supplier_contacts`. As linhas 6 e 7 abaixo descrevem as colunas comuns a essas tabelas. Um único endereço `main` e um único contato principal por dono (coluna gerada no índice único).

Todas operacionais: `id`, `tenant_id`, `created_at`, `updated_at`, `UNIQUE (tenant_id, id)`, FKs **compostas** `(tenant_id, x_id)`, todas `RESTRICT`, sem triggers, `BelongsToTenant` fail-closed. `SD` = soft delete.

| # | Tabela | Colunas principais | Unicidade |
|---|---|---|---|
| 1 | `units_of_measure` | `code`, `name`, `status` | `(tenant_id, code)` |
| 2 | `companies` (SD) | `uuid`, `legal_name`, `trade_name`, `document_number` (CNPJ, só dígitos), `state_registration`, `municipal_registration`, `email`, `phone`, `status` | `(tenant_id, document_number)` ativo |
| 3 | `company_branches` (SD) | `company_id`, `name`, `document_number` NULL, `status` | CNPJ da filial ativo, quando houver |
| 4 | `customers` (SD) | `uuid`, `company_id`, `type` (person/company), `document_number` NULL (CPF/CNPJ), `name`, `email`, `phone`, `status` | `(tenant_id, company_id, document_number)` ativo |
| 5 | `suppliers` (SD) | `uuid`, `company_id`, `type` (person/company, **D-30**), `document_number` (CPF ou CNPJ conforme `type`), `legal_name`, `trade_name`, `email`, `phone`, `status` | `(tenant_id, company_id, document_number)` ativo |
| 6 | `contacts` | dono, `name`, `role`, `email`, `phone`, `is_primary` | 1 principal por dono |
| 7 | `addresses` | dono, `kind` (main/billing/delivery/site), `street`, `number`, `complement`, `district`, `city`, `state`, `postal_code`, `latitude`, `longitude` | — |
| 8 | `document_categories` | `parent_id`, `code`, `name` | `(tenant_id, code)` |
| 9 | `documents` | `uuid`, `category_id`, `title`, `current_version_id`, `status`, `visibility`, `created_by` | — |
| 10 | `document_versions` (append-only) | `document_id`, `version_number`, `storage_disk`, `storage_key`, `original_name`, `mime_type`, `size_bytes`, `sha256`, `uploaded_by` | `(tenant_id, document_id, version_number)` |
| 11 | `document_links` | `document_id`, dono do vínculo | `(tenant_id, document_id, dono)` |
| 12 | `document_permissions` | `document_id`, `subject_type`, `subject_id`, `ability` | `(tenant_id, document_id, subject, ability)` |

Convenções herdadas: datas UTC; CNPJ/CPF gravados **só com dígitos** e validados (dígitos verificadores) na aplicação; `utf8mb4_0900_ai_ci` para nomes.

## 3. Decisões (todas aprovadas conforme a recomendação, 2026-11-13)

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P11** | **Tenant × empresa.** O tenant (HighTech) é o cliente do SaaS; `companies` são as pessoas jurídicas que ele administra. O CNPJ 61.693.043/0001-94 vai em `tenants.document_number`, na **primeira empresa** (`companies`) ou nos dois? | Nos dois, com a razão social real; a empresa é criada na Fase 2 por API, não por script |
| **D-P12** | **Contatos e endereços polimórficos** (`contactable_type/id`): o banco **não consegue** impor FK composta em coluna polimórfica, o que enfraquece a regra "o banco garante o isolamento". Opções: (A) polimórfico + validação na aplicação + morph map + teste; (B) tabelas dedicadas por dono (`company_addresses`, `customer_contacts`…) com FK composta real | **B** para empresa/filial/cliente/fornecedor (integridade igual à Fase 1; mais tabelas, mas simples). Polimórfico só em `document_links` |
| **D-P13** | **Soft delete × unicidade.** `UNIQUE` simples bloqueia recadastrar um CNPJ excluído. Uso coluna gerada `active_marker` (NULL quando excluído) dentro do índice único, como `primary_marker` na Fase 1 | Aprovar |
| **D-P14** | **`units_of_measure` global (`tenant_id NULL`) ou por tenant.** NULL em FK composta não funciona e quebra o isolamento por constraint | **Por tenant** (`tenant_id NOT NULL`), semeada no provisionamento (m, m², m³, kg, un, h…); para a HighTech seria uma escrita no banco, **só com sua autorização** |
| **D-P15** | **Documentos e S3.** Não há bucket nem credenciais. Proposta: tabelas e regras completas, armazenamento atrás de uma interface de `disk` (**disco local privado** no desenvolvimento, S3 por configuração), **URL assinada de curta duração**, nunca URL pública, limites de tamanho e tipo | Aprovar; S3 real fica para quando você fornecer o bucket |
| **D-P16** | **`user_companies`** (acesso por empresa): entra agora ou na Fase 3 (acesso por obra)? | **Fase 3** |
| **D-P17** | **Cliente/fornecedor por empresa** (`company_id` obrigatório, como em DATABASE.md) ou **único por tenant**? | Manter por empresa, como documentado; confirme se o mesmo fornecedor atende várias empresas |
| **D-P18** | **Telas.** Backend/API + testes primeiro, ou incluir a tela de login e os cadastros no React (a tela de referência `Perfil_Empresa` já existe)? | Backend primeiro; tela de login e Empresa em seguida, em etapa própria |

## 4. Permissões e API (resumo)

Novas permissões no catálogo (sincronizadas por `obraflow:sync-permissions`; **papéis existentes só mudam com autorização**): `companies.view/manage`, `customers.view/manage`, `suppliers.view/manage`, `documents.view/manage`, `units.view/manage`. Tokens: `*.view` podem ser abilities; `*.manage` apenas conforme a regra D-29. Rotas em `routes/api/v1/registry.php` sob `tenant.required`; Form Requests escopados ao tenant; Policies; respostas 404 idênticas para inexistente × outro tenant; API Resources sem vazar atributos; auditoria das mutações (novos eventos no catálogo).

## 5. Etapas de implementação (após aprovação)

1. Unidades e empresas/filiais (migrations + modelos + testes de integridade).
2. Clientes, fornecedores, contatos e endereços.
3. Documentos (metadados, versões, URL assinada, disco local).
4. Permissões, Policies, API e auditoria.
5. Testes de isolamento cruzado, CI em MySQL 8.0 e 8.4, documentação.

Cada etapa valida e relata antes da seguinte; migrations só rodam em `obraflow_testing` até você autorizar `migrate` no `obraflow`.

## 6. Riscos

CNPJ duplicado entre tenants é **permitido** (cada tenant tem seu cadastro); validar dígitos não prova existência na Receita; dados pessoais de clientes/contatos entram na LGPD (D-P07); anexos exigem política de tamanho/tipo/antivírus antes de produção.

## 7. Andamento

| Etapa | Estado | Testes |
|---|---|---|
| 1 — Unidades, empresas, filiais (+ endereços/contatos) | ✅ commit `3201b5a` | 212 |
| 2 — Clientes, fornecedores (+ endereços/contatos) | ✅ commit `0e9542a` | 226 |
| 3 — Documentos + modelos Eloquent de toda a Fase 2 | ✅ local | 258 (1.363 asserções) |
| 4 — Permissões, Policies, API, auditoria | ⏳ | |
| 5 — Isolamento cruzado, CI, documentação | ⏳ | |

CHECK de cliente/fornecedor: `type = person` exige CPF de 11 dígitos; `company`, CNPJ de 14; documento opcional só no cliente. Dígitos verificadores são validados na aplicação (etapa 4).

### Etapa 3 — detalhes de implementação

- **Modelos** (`app/Models`): os 18 da Fase 2, todos com `BelongsToTenant` (fail-closed), uuid público nas entidades expostas, CPF/CNPJ/CEP normalizados para dígitos e UF em maiúsculas. Aliases no morph map: `company`, `company_branch`, `customer`, `supplier` (os únicos aceitos em `document_links`), `unit_of_measure`, `document`, `document_version`. *(Os modelos estavam previstos para a etapa 1 e foram entregues aqui.)*
- **`DocumentStorage`** (único ponto de escrita): tipo detectado pelo **conteúdo** (`finfo`), limite de tamanho, chave `{tenant_uuid}/{document_uuid}/{aleatório}` sem o nome original, arquivo gravado antes da transação e **removido se o banco falhar**, número de versão sob *lock*, versão corrente atualizada na mesma transação; `link()` confere tipo, existência, exclusão lógica e tenant do dono (a tabela é polimórfica).
- **Disco `documents`**: raiz `storage/app/documents` (fora da raiz do disco `local`, também servido; fora do Git). Arquivos servidos em `/files/documents/...` **somente com assinatura válida** (`ServeFile` do Laravel, visibilidade privada), validade padrão 5 min, cabeçalhos `no-store` e CSP `sandbox`. Em produção, `DOCUMENTS_DISK` aponta para S3.
- **`document_versions` append-only** pela aplicação (modelo e builder). O `ImmutableBuilder` da Fase 1 passou a citar a tabela correta na mensagem (antes, fixo em `audit_logs`).
- **Limitação:** a URL assinada não revalida o vínculo do usuário no momento do download; a proteção é a validade curta. A emissão da URL (etapa 4) exigirá permissão e tenant ativo. Auditoria de documentos entra na etapa 4.
