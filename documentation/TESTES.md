# TESTES.md — Como executar e o que é coberto

## 1. Executar

```bash
# Backend (MySQL 8.x; usa o banco 'obraflow_testing')
cd obraflow-api
vendor/bin/pest                    # suíte completa (~30 s)
vendor/bin/pest tests/Feature/Tenancy
vendor/bin/pint --test             # estilo

# Frontend — no Windows rode pelo PowerShell/CMD (Git Bash quebra o Vitest por diferença de caminho)
cd obraflow-web
npm test && npm run lint && npm run build
```

**Pré-requisito:** banco `obraflow_testing` e usuário da aplicação ([AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md)). O `phpunit.xml` **força** `DB_DATABASE=obraflow_testing` e o `TestCase` **aborta** se a conexão apontar para outro banco (a suíte usa `migrate:fresh`).

## 2. Resultado da Fase 1

| Área | Arquivo | Testes |
|---|---|---|
| Esquema, FKs, CHECK, colunas geradas, UTC | `Feature/Database/*` | 35 |
| Migrations up/down (MySQL 8.0.30) | `Feature/Migrations` | 2 |
| Modelos, `BelongsToTenant`, `TenantContext`, morph map | `Feature/Models` | 16 |
| Login, sessões, recuperação de senha | `Feature/Auth/LoginSessionTest` | 15 |
| Tokens (emissão, uso, expiração, revogação) | `Feature/Auth/TokenTest` | 16 |
| **Escopo dos tokens (D-29):** abilities obrigatórias, sem curinga, interseção com o usuário, sem tenant, **rotas sem `SetTenantContext`** | `Feature/Auth/TokenAbilitiesTest` | 18 |
| Isolamento, seleção/troca de tenant, bloqueios, abas | `Feature/Tenancy` | 17 |
| Papéis, permissões, vínculos, último administrador | `Feature/Access/AccessControlTest` | 23 |
| Convites | `Feature/Access/InvitationTest` | 15 |
| Provisionamento e comandos | `Feature/Provisioning` | 16 |
| Auditoria (catálogo, atomicidade, escopo, imutabilidade) | `Feature/Audit` | 11 |
| Sanitizador de auditoria | `Unit/Audit` | 5 |
| API: erros padronizados e health | `Feature/Core` | 9 |
| **Backend** | | **198 (1.101 asserções)** |
| **Frontend** (cálculos de orçamento, hint, BroadcastChannel) | `src/**/*.test.ts` | **14** |

## 3. Requisitos obrigatórios × testes

| # | Requisito | Onde |
|---|---|---|
| 1 | Usuário global em vários tenants | `ModelsTest`, `TenantIsolationTest` ("usuário global…") |
| 2 | Impossível acessar dados de outro tenant | `TenantIsolationTest` ("um tenant não enxerga…"), `TokenTest`, `InvitationTest`, `AccessControlTest` (404) |
| 3 | Impossível atribuir papel de outro tenant | `AccessControlTest` (API 404, serviço, **banco 1452**) |
| 4 | Impossível atribuir papel a usuário sem vínculo | `AccessControlTest`, `TenantIntegrityTest` (banco 1452) |
| 5 | Bloqueio de usuário suspenso | `LoginSessionTest`, `TenantIsolationTest`, `ProvisioningTest` |
| 6 | Vínculo suspenso/removido | `TenantIsolationTest`, `AccessControlTest` |
| 7 | Tenant suspenso/cancelado | `TenantIsolationTest`, `ProvisioningTest` (comando de status) |
| 8 | Token vinculado ao tenant correto | `TokenTest`, `TokenInvitationAuditIntegrityTest` |
| 9 | Token sem tenant sem dados de negócio | `TokenTest` |
| 10 | Expiração e revogação de tokens | `TokenTest` |
| 11 | Troca de tenant e contexto de permissões | `TenantIsolationTest`, `ModelsTest` (armadilha R14) |
| 12 | Inconsistência entre abas | `TenantIsolationTest` (`X-Tenant-Hint`), `tenant-context.test.ts` (BroadcastChannel) |
| 13 | Auditoria das operações críticas | `AuditTest` (cobertura do catálogo inteiro; rollback) |
| 14 | Auditoria imutável pela aplicação | `ModelsTest`, `AuditTest` (modelo, builder, API) |
| 15 | Proteção do último administrador | `AccessControlTest`, `ProvisioningTest` |
| 16 | Autenticação com sessão e com token | `LoginSessionTest`, `TokenTest` ("funciona igual…") |
| 17 | Round-trip de datas em UTC | `TokenInvitationAuditIntegrityTest` |
| 18 | Migrations em MySQL 8.0.30 | `SchemaTest`, `MigrationsTest` (8.0.30 local; **8.0 e 8.4 no CI**) |

## 4. CI

`.github/workflows/ci.yml`: matriz **MySQL 8.0 e 8.4**, `composer audit`, Pint, Pest; web com lint, testes, build e `npm audit --omit=dev`. **CI executado e aprovado** (GitHub Actions, commit `eeb84fd`, run 43307638571): API/Pest em **MySQL 8.0 e 8.4**, `composer audit`, Pint, e Web (lint, testes, build, `npm audit --omit=dev`) — todos `success`. A versão do MySQL vem da matriz do workflow (imagem `mysql:8.0`/`mysql:8.4`); o log do passo de versão não foi lido. https://github.com/andersonsilvasilva/obraflow/actions/runs/43307638571

## 5. O que o ambiente de teste precisou simular

Dentro de um teste o app é reaproveitado entre requisições (em produção, cada requisição é um processo novo). O `TestCase` (`call()`) por isso esquece, antes de cada requisição: guards de autenticação, instâncias com escopo (`TenantContext`), o driver/`Store` de sessão e o cache de controllers das rotas. O helper `Browser` mantém o cookie jar e usa `withCredentials()` (requisições JSON só enviam cookies assim). Sessões usam driver `file` (o `array` não persiste). **Sem essas simulações os testes passavam "por vazamento de memória"**, escondendo defeitos — por isso foram feitas.

## 6. Limites

- Não há teste de carga nem de concorrência real (o *lock* de linha é verificado pela consulta, não por duas conexões simultâneas).
- Imutabilidade de `audit_logs` por **privilégios do banco** foi validada na instância descartável ([validacao-fase1/](validacao-fase1/README.md)); no `obraflow_testing` a aplicação tem todos os privilégios (precisa rodar migrations), então os testes cobrem a trava do **modelo**.
- Redis não é exercitado (adiado).
