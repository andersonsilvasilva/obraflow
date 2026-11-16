# FASE 2 — CADASTROS: RELATÓRIO TÉCNICO

**Escopo:** unidades de medida, empresas, filiais, clientes, fornecedores, endereços, contatos e documentos — modelo, API, permissões e auditoria. Sem telas (D-P18) e sem módulos de fases seguintes.
**Modelo aprovado:** [FASE2_MODELO.md](FASE2_MODELO.md) (D-P11…D-P18, D-30). **Ambiente:** PHP 8.4.2 · Laravel 13.42 · MySQL 8.0.30 local; CI em MySQL 8.0 e 8.4.

## 1. Critérios de conclusão

| Critério | Estado | Evidência |
|---|---|---|
| 18 tabelas com isolamento por FK composta | ✅ | `RegistryIntegrityTest`, `DocumentIntegrityTest` (erros 1452/3819/1062/1451 do próprio banco) |
| Revisão estrutural do isolamento | ✅ | `IsolationStructureTest`: toda tabela com `tenant_id` tem modelo com escopo fail-closed; nenhuma FK simples entre tabelas operacionais; `UNIQUE (tenant_id, id)` e `tenant_id NOT NULL` |
| Modelos com escopo de tenant | ✅ | `RegistryModelsTest` (consulta sem tenant lança exceção; `tenant_id` só do contexto) |
| API com permissões e 404 entre tenants | ✅ | `RegistryApiTest` (14), `DocumentApiTest` (11) |
| Documentos privados, versões, URL assinada | ✅ | `DocumentStorageTest` (9), `DocumentApiTest` (assinatura adulterada → 403; expiração; auditoria falha fechada) |
| Auditoria de todas as mutações | ✅ | Cobertura do catálogo da Fase 2 (`AuditEvent::phase2()`) — todo evento é emitido por operação real |
| Tokens respeitam as novas permissões (D-29) | ✅ | testes de token em `RegistryApiTest` e `DocumentApiTest`; teste estrutural `SetTenantContext` cobre as 56 rotas novas |
| CI em MySQL 8.0 e 8.4 | ✅ | etapas 1–4 verdes (tabela §3) |
| Testes | ✅ | **Backend 291/291 (1.700 asserções)** no fechamento (286/1.670 na homologação) · Frontend 14/14 · Pint e `composer audit` limpos |
| Banco `obraflow` atualizado (autorizado) | ✅ | 33 migrations; 21 permissões; papel `admin` da HighTech com as 10 novas (`permission.granted` auditado) |

## 2. Etapas

| Etapa | Entrega | Commit | Testes |
|---|---|---|---|
| 1 | Unidades, empresas, filiais + endereços/contatos (7 tabelas) | `3201b5a` | 212 |
| 2 | Clientes, fornecedores + endereços/contatos (6 tabelas); D-30 | `0e9542a` | 226 |
| 3 | Documentos (5 tabelas), 18 modelos, `DocumentStorage`, disco privado | `136d782` | 258 |
| 4 | API, permissões, acesso por documento, auditoria | `9af4dd7` | 283 |
| 5 | Revisão estrutural do isolamento, relatório, banco `obraflow` | este commit | 286 |

## 3. CI (GitHub Actions)

| Commit | Run | API MySQL 8.0 | API MySQL 8.4 | Web |
|---|---|---|---|---|
| `3201b5a` | 43427904447 | ✅ | ✅ | ✅ |
| `0e9542a` | 43429370898 | ✅ | ✅ | ✅ |
| `136d782` | 43432547796 | ✅ | ✅ | ✅ |
| `9af4dd7` | 43434871005 | ✅ | ✅ | ✅ |

## 4. Defeitos encontrados e corrigidos

1. **Tipo de arquivo pela extensão:** `UploadedFile::getMimeType()` caía na extensão; passou a usar `finfo` sobre o conteúdo (um `.pdf` com PHP é recusado).
2. **Disco de documentos dentro do disco `local` servido:** uma URL assinada daquele disco alcançaria os documentos; a raiz foi movida para fora.
3. **`Storage::fake` troca o gerador de URL assinada:** os testes passavam com uma URL sem assinatura; agora usam o disco real em pasta descartável.
4. **Mensagem do `ImmutableBuilder`** fixa em `audit_logs`; passou a citar a tabela real.
5. **Erros meus de organização:** dois traits num arquivo (PSR-4) e modelos previstos na etapa 1 entregues na etapa 3 — corrigidos e registrados.
6. **Formato de erro nos testes:** asserções escritas para `code`; a API padroniza em `error.code`/`error.details`.

## 5. Riscos e limitações

| # | Risco | Estado |
|---|---|---|
| R1 | URL assinada já emitida continua utilizável até expirar (máx. 5 min), **mesmo se as permissões do usuário mudarem nesse intervalo** | **Aceito pelo responsável** (§7; reconfirmado em 2026-11-16) |
| R2 | **Sem inspeção antimalware** nos anexos | **Decisão do responsável (2026-11-16): inspeção antimalware não é necessária** e não será implementada. Mantidos os controles do §7 (tipo pelo conteúdo, lista fechada, tamanho, disco privado, URL curta, CSP `sandbox`) |
| R3 | S3 não configurado (disco local privado) | Configurar `DOCUMENTS_DISK` quando houver bucket |
| R4 | `document_links` é polimórfica: o dono é garantido pela aplicação, não por FK | Aceito (D-P12); coberto por testes |
| R5 | Rebaixamento automático do contato principal não gera evento próprio | Aceito; documentado |
| R6 | Validar dígitos não prova que o CPF/CNPJ existe na Receita | Aceito |
| R7 | Dados pessoais de clientes/contatos (LGPD, D-P07) | Política de retenção pendente |
| R8 | ~~`member` da HighTech sem leitura de cadastros~~ | ✅ Resolvido (§7) |

## 6. Pendências

- Telas (login, cadastros) — etapa própria (D-P18).
- ~~Analisar `Cadastro de Produtos.png`~~ — feito ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md)); tela **não** implementada.
- ~~Novas referências de tela~~ — analisadas; padrão de layout registrado em [SCREEN_REFERENCES.md](SCREEN_REFERENCES.md). Planilhas de importação (`planilha para importar recursos/`) não analisadas.
- Fase 3 (Equipes e Obras): somente após aprovação expressa da homologação da Fase 2.

## 7. Homologação e encerramento (2026-11-16)

**Homologação técnica aprovada pelo responsável**, condicionada às atividades abaixo — todas concluídas.

| Atividade | Resultado |
|---|---|
| Leitura de cadastros ao papel `member` da HighTech | ✅ `obraflow:system-roles:sync hightech --role=member` (comando idempotente, só acrescenta). Lista apresentada antes (simulação): `units.view`, `companies.view`, `customers.view`, `suppliers.view`, `documents.view`. Nenhuma permissão de escrita, administrativa ou de outros módulos. `member` = 9 permissões, todas de consulta. Evento `permission.granted` (13º da auditoria). Segunda execução: "nada a fazer" |
| Testes da alteração | ✅ `Feature/Provisioning/SyncSystemRolesTest` (5): simulação não grava; concede só leitura; audita; idempotente; não remove permissões; não toca outros papéis nem outro tenant; efeito na API (lê, não escreve; `audit-logs` continua 403) |
| Referência `Cadastro de Produtos.png` | ✅ Analisada em [SCREEN_REFERENCES.md](SCREEN_REFERENCES.md) (só o visível; sem implementação) |
| Isolamento após as mudanças | ✅ 140/140 em `Registry`, `Tenancy` e `Database` (inclui a revisão estrutural); suíte completa 291/291 |

**Condições sobre anexos (decisão do responsável):**
- **URLs assinadas:** validade máxima de **5 minutos** (`DOCUMENTS_SIGNED_URL_MINUTES`); autorização do usuário **e** do tenant verificadas **na emissão** (rota em `tenant.required` + `DocumentAccess` + permissão); nenhuma URL permanente (a chave de armazenamento nunca é exposta; sem assinatura o servidor recusa). Uma URL já emitida pode ser usada até expirar, mesmo após mudança de permissões.
- **Antivírus:** **não há inspeção antimalware** — e, por decisão posterior do responsável (2026-11-16), ela **não é necessária**. Controles atuais: tipo verificado pelo conteúdo (lista fechada: PDF, PNG, JPEG, WebP, DOCX, XLSX), limite de tamanho, disco privado fora da área pública, acesso somente com permissão e URL assinada curta, resposta com `Content-Security-Policy: sandbox` e `no-store`.
- **S3:** arquitetura pronta (disco configurável por `DOCUMENTS_DISK`; `temporaryUrl` nativa do S3); **nenhuma migração de infraestrutura** foi feita.

**Telas (D-P18):** mantidas para etapa própria. A API segue compatível com o frontend React: sessão SPA (Sanctum), JSON padronizado (`data`/`meta`, `error.code`/`error.details`), uuid público, paginação e filtros por query string.

**Decisões posteriores (2026-11-16):** inspeção antimalware dispensada pelo responsável (R2); risco da URL assinada reconfirmado (R1); padrão de layout definido (A-33).

**Fase 3 (Equipes e Obras): autorizada em 2026-11-16** — começa pela proposta de modelo.