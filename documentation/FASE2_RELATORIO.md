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
| Testes | ✅ | **Backend 286/286 (1.670 asserções)** · Frontend 14/14 · Pint e `composer audit` limpos |
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
| R1 | URL assinada válida por 5 min não revalida o vínculo no download | Aceito (validade curta; emissão exige permissão e é auditada) |
| R2 | Sem antivírus nos anexos; limite de 20 MB e lista fechada de tipos | Antes de produção |
| R3 | S3 não configurado (disco local privado) | Configurar `DOCUMENTS_DISK` quando houver bucket |
| R4 | `document_links` é polimórfica: o dono é garantido pela aplicação, não por FK | Aceito (D-P12); coberto por testes |
| R5 | Rebaixamento automático do contato principal não gera evento próprio | Aceito; documentado |
| R6 | Validar dígitos não prova que o CPF/CNPJ existe na Receita | Aceito |
| R7 | Dados pessoais de clientes/contatos (LGPD, D-P07) | Política de retenção pendente |
| R8 | `member` da HighTech **não** recebeu as permissões de leitura de cadastros | Aguarda decisão |

## 6. Pendências

- Decidir se o papel `member` da HighTech recebe `*.view` dos cadastros (R8).
- Telas (login, cadastros) — etapa própria (D-P18).
- Analisar a nova referência `documentation/telas/Cadastro de Produtos.png` (não versionada).
- Fase 3 (Equipes e Obras): somente após aprovação expressa da homologação da Fase 2.
