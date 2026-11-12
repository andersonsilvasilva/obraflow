# FASE 1 — RELATÓRIO TÉCNICO

**Escopo:** identidade global, tenancy, autenticação, controle de acesso, convites, provisionamento e auditoria (15 tabelas). Sem módulos de negócio.
**Ambiente:** PHP 8.4.2 · Laravel 13.42 · Sanctum 4.4 · Spatie Permission 8.5.0 · Pest 4.7 · MySQL **8.0.30** (Laragon) · Redis adiado.

## 1. Resultado dos critérios de conclusão

| Critério | Estado | Evidência |
|---|---|---|
| 15 migrations executadas com sucesso | ✅ | `php artisan migrate` (15 DONE) no banco `obraflow`; `migrate:fresh`/rollback/up no `obraflow_testing` (`MigrationsTest`) |
| Restrições de integridade funcionando | ✅ | `Feature/Database/*` (35 testes: FKs compostas, CHECK, UNIQUE, colunas geradas, RESTRICT) |
| Autenticação operacional | ✅ | `LoginSessionTest` (15), `TokenTest` (16) |
| Seleção e troca de tenant | ✅ | `TenantIsolationTest` (17) |
| Isolamento multi-tenant comprovado | ✅ | `TenantIsolationTest`, `AccessControlTest` (banco recusa cruzamento: erro 1452), `ModelsTest` |
| Spatie e Sanctum integrados | ✅ | `AccessControlTest` (23), `TokenTest`; morph map; FKs compostas nas tabelas do pacote |
| Auditoria operacional | ✅ | `AuditTest` (11): **os 32 eventos do catálogo são emitidos**; atomicidade; escopo; imutabilidade |
| Provisionamento inicial | ✅ | `ProvisioningTest` (16) |
| Testes automatizados aprovados | ✅ | **Backend 180/180 (963 asserções) · Frontend 14/14**; Pint e `composer audit` limpos; build e lint do frontend limpos |
| Documentação atualizada | ✅ | [AUTENTICACAO.md](AUTENTICACAO.md), [PROVISIONAMENTO.md](PROVISIONAMENTO.md), [TESTES.md](TESTES.md), [AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md) + atualização de DATABASE, DB_CONSTRAINTS, MULTITENANCY, DB_MIGRATION_PLAN, DB_DECISIONS, ARCHITECTURE, API, SECURITY, ROADMAP, DECISIONS |
| **Compatibilidade com MySQL 8.4** | ⏳ **NÃO comprovada** | Matriz 8.0/8.4 está no CI, mas o CI **ainda não rodou** (sem push). **Obrigatório antes da homologação.** |

## 2. Etapas e entregas

| Etapa | Entrega | Testes ao fim da etapa |
|---|---|---|
| 1 | Bancos `obraflow`/`obraflow_testing`, usuário exclusivo, `timezone +00:00`, drivers locais, health sem Redis obrigatório | 9 (anteriores) |
| 2 | 15 migrations + testes de integridade | 46 |
| 3 | Modelos, enums, `TenantContext`, `BelongsToTenant`, morph map | 62 |
| 4 | Login/logout/me, recuperação de senha, tokens, sessões | — |
| 5 | `SetTenantContext`, seleção/troca de tenant, `X-Tenant-Hint` | 110 (etapas 4+5) |
| 6–7 | Papéis, permissões, vínculos, último admin, convites, provisionamento, comandos | 164 |
| 8 | Auditoria: catálogo completo, atomicidade, sanitização | **180** |
| 9 | CI (MySQL 8.0/8.4), frontend (`X-Tenant-Hint`, `BroadcastChannel`), documentação | 180 + 14 |

## 3. Defeitos encontrados e corrigidos durante a implementação

1. **Os testes de sessão "passavam" por vazamento de memória**: o app é reaproveitado entre requisições do mesmo teste, e o guard do Sanctum, o `Store` de sessão e o cache de controllers retinham estado; além disso, requisições JSON só enviam cookies com `withCredentials()`. O `TestCase`/`Browser` agora simulam um processo por requisição. **Teria escondido falhas reais.**
2. **Serviço de sessão duplicado:** escrevi um middleware de "sessão fresca"; os testes mostraram que o `AuthenticateSession` do **Sanctum** já faz o trabalho. Removi o meu.
3. **Auditoria × sessão:** a troca de tenant gravava na sessão **antes** da auditoria; se a auditoria falhasse, a troca valeria sem registro. Passou a gravar na sessão **depois do commit**.
4. **Login com auditoria fora do ar** derrubava o login de quem tem um tenant (a seleção automática é falha fechada). Agora o login prossegue **sem** tenant ativo.
5. **Aceite de convite (rota pública):** o escopo global exigia tenant até para o `UPDATE`; passou a rodar no contexto do tenant do convite (a falha fechada funcionou como projetada).
6. **Testes fracos:** duas asserções passavam por acaso (`hasRole` fora de contexto retorna falso). Reescritas com helper de contexto; uma simulação de falha de auditoria disparava *depois* do `INSERT` e foi trocada por `beforeExecuting`.
7. **`Role` não pode ter escopo global de tenant** (cache global de permissões do Spatie) — decisão D-24.

## 4. Riscos técnicos ainda existentes

| # | Risco | Estado |
|---|---|---|
| R1 | **MySQL 8.4** não testado; **CI nunca executado** | Pendente — bloqueia a homologação |
| R2 | Token age com as permissões do usuário emissor; `abilities` não restringem rotas (D-29) | Pendente de decisão |
| R3 | **Abas do navegador:** mitigado (`X-Tenant-Hint`, `BroadcastChannel`), mas não eliminado — a sessão tem um tenant por navegador | Documentado |
| R4 | Imutabilidade de `audit_logs` depende de **privilégios do banco em produção**; no dev (usuário com todos os privilégios) só vale a trava do modelo. Sem triggers | Configurar o usuário de produção |
| R5 | Sem teste de concorrência real (o *lock* é verificado pela consulta, não por duas conexões) | Aceito |
| R6 | Redis adiado: *rate limiting* e cache em arquivo não são compartilháveis entre servidores | Resolver ao adotar o Redis ([AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md) §4) |
| R7 | Sem **tela de login** no frontend (fora do escopo); `setActiveTenant()` ainda não é chamado por nenhuma tela | Fase de UI |
| R8 | E-mail: driver `log` (não envia de verdade); sem SMTP configurado | Configurar para produção |
| R9 | Cada requisição de negócio faz 1 consulta de vínculo + auditoria síncrona; sem cache de propósito | Medir antes de otimizar |
| R10 | Política de retenção/particionamento de `audit_logs` e `failed_jobs` (dados pessoais) indefinida (D-P06/P07) | Pendente |
| R11 | MFA e TLS/HSTS/CSP não implementados | Fases futuras |
| R12 | Banco compartilhado com outros projetos no Laragon | Mitigado: usuário com acesso só a `obraflow*` |

## 5. Pendências (itens que não foram feitos)

- Executar o CI e comprovar o MySQL 8.4 (R1).
- Decidir o escopo por `abilities` dos tokens (D-29).
- Criar o tenant inicial no banco `obraflow` (`obraflow:provision`) — **não executei** (não foi autorizado criar dados nele).
- Análise das 9 telas de referência novas (Cadastro de Recursos, Perfil…).
- Decidir o destino das pastas SINAPI de MT (2024-12) em `documentation/` (≈ 50 MB; referência, **sem** importação — a Fase 4 trata disso).
- D-P02 (BDI) e D-P03 (encargos): adiadas para a Fase 4, conforme aprovação.

## 6. Próximos passos recomendados

1. Subir o repositório e **ver o CI verde nas duas versões do MySQL**.
2. Rodar `obraflow:provision` no banco `obraflow` para criar o primeiro tenant/administrador.
3. Definir a política de escopo de tokens e a retenção da auditoria.
4. Fase 2 (Cadastros) — somente após autorização.
