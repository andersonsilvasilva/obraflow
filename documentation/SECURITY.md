# SECURITY.md

## 1. Princípios

1. **Isolamento multi-tenant é prioridade absoluta** ([MULTITENANCY.md](MULTITENANCY.md)).
2. Menor privilégio: tenant → empresa → obra → permissão.
3. Falhar fechado: sem contexto de tenant, o acesso é negado.
4. Nenhum segredo no repositório.

## 2. Segredos e configuração

- `.env` nunca versionado (`.gitignore` raiz); somente `.env.example` sem valores reais.
- Docker Compose usa `${VAR:?mensagem}` — o ambiente **não sobe** sem `APP_KEY`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `REDIS_PASSWORD`.
- `.dockerignore` impede que `.env` entre nas imagens.
- Variáveis `VITE_*` são **públicas** (embutidas no bundle): nunca colocar segredos nelas.
- Senhas, tokens e chaves **não** são gravados em logs nem em `audit_logs`.

## 3. Autenticação e sessão

- Laravel Sanctum: SPA por cookie (CSRF via `/sanctum/csrf-cookie`) e tokens vinculados a tenant (D-05).
- **Token sem `tenant_id` não acessa dados de negócio** (D-20); com tenant, só no **seu** tenant e com vínculo ativo reavaliado a cada uso. Suspender/remover o vínculo ou bloquear o usuário **revoga** os tokens. Recomendado: `expires_at` obrigatório.
- **Toda requisição de negócio revalida** o vínculo ativo e o status do tenant/usuário; a troca de tenant regenera a sessão e é auditada.
- Sessão compartilhada entre abas: limitação conhecida ([MULTITENANCY.md](MULTITENANCY.md) §3).
- Senhas com hash (`BCRYPT_ROUNDS=12`); convites e recuperação guardam **apenas hash** do token.
- MFA: previsto (D-P08), não implementado.
- *Rate limiting* em login/recuperação/convites: a implementar com o módulo de autenticação.

### 3.1 Implementado na Fase 1 (resumo; detalhes em [AUTENTICACAO.md](AUTENTICACAO.md))

- Limites de tentativa: `login` 5/min e `password-reset`/`invitations/accept` 3/min.
- Resposta de login idêntica para conta inexistente e senha errada; hash sempre verificado; recuperação de senha sempre `202`.
- Convites: token só por e-mail (no banco só o hash); aceite com respostas iguais para token desconhecido/usado/revogado.
- Tokens: expiração obrigatória (≤ 90 dias), valor exibido uma vez, revogação automática em suspensão/remoção/bloqueio/troca de senha.
- **Anti-escalação de privilégio:** ninguém concede permissões, atribui papel ou convida com papel que contenha permissões que o autor não possui.
- **Último administrador ativo** protegido sob *lock* de linha (sem corrida).
- Auditoria de acesso completa (32 eventos, todos exercitados por testes); segredos mascarados por chave e por valor.
- **Tokens (D-29):** menor privilégio — abilities obrigatórias e explícitas, sem curinga, nunca de gestão de acesso; autorização = abilities ∩ permissões vivas do usuário; nenhuma rota de negócio escapa do `SetTenantContext` (teste estrutural).
- ⚠️ Pendências: MFA; TLS/HSTS/CSP; política de retenção da auditoria.

## 4. Autorização

- Policies em operações protegidas; Spatie Teams por tenant.
- Validação de empresa e obra em todos os relacionamentos.
- Respostas 404 idênticas para inexistente × outro tenant.

## 5. API

- Erros padronizados; **5xx nunca expõem detalhes internos** fora de `APP_DEBUG` (testado).
- `X-Request-Id` aceito apenas se casar `^[A-Za-z0-9\-_]{8,64}$` (evita injeção em logs/cabeçalhos; testado).
- Entrada validada por Form Requests; saída por API Resources (sem vazar atributos).

## 6. Infraestrutura

- Apenas o Nginx publica porta; MySQL/Redis em rede interna `backend`.
- Redis com `--requirepass`; MySQL sem acesso externo.
- Nginx: `server_tokens off`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- Pendente: TLS, HSTS, CSP, backups e rotação de segredos (produção).

## 7. Dados e LGPD

- Dados de empregados/clientes: acesso restrito, mascaramento em logs/exports.
- Documentos privados em Object Storage com **URL assinada de curta duração**; nunca URL pública permanente.
- IA: nenhum dado de cliente é enviado a provedor externo sem autorização e redação ([DB_DECISIONS.md](DB_DECISIONS.md) D-17).

## 8. Auditoria

`audit_logs` append-only. Catálogo de acesso na Fase 1: login, logout, falha de login (HMAC do e-mail), seleção de tenant, convites, vínculos, papéis, permissões e tokens ([FASE1_MODELO.md](FASE1_MODELO.md) §6). Eventos de autorização são gravados **na mesma transação** da mudança. Imutabilidade por privilégios (INSERT/SELECT) e guarda no modelo; **sem triggers** (D-22). `AuditSanitizer` bloqueia senha, token, hash e `remember_token`, com testes.

## 9. Dependências

Versões estáveis e atuais; `composer audit` / `npm audit` no CI (a adicionar). Nenhuma biblioteca sem justificativa.

## 10. Checklist para novos módulos

- [ ] `tenant_id` + FKs compostas + `BelongsToTenant`
- [ ] Form Request com regras escopadas ao tenant
- [ ] Policy + testes de autorização
- [ ] Testes de acesso cruzado entre tenants
- [ ] Auditoria das operações sensíveis
- [ ] Nenhum segredo/PII em logs
