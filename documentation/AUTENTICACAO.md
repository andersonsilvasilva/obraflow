# AUTENTICACAO.md — Autenticação, sessões, tokens e tenant ativo

> Implementado na Fase 1. Especificação do modelo: [FASE1_MODELO.md](FASE1_MODELO.md). Isolamento: [MULTITENANCY.md](MULTITENANCY.md). Evidência de testes: [TESTES.md](TESTES.md).

## 1. Dois canais, uma regra

| Canal | Para quem | Autenticação | Tenant ativo vem de |
|---|---|---|---|
| **SPA** | Pessoas no navegador | Cookie de sessão (Sanctum stateful) | **Sessão do servidor** após seleção explícita |
| **API** | Integrações | `Authorization: Bearer <token>` | `personal_access_tokens.tenant_id` (fixado na emissão) |

**O cliente nunca informa o tenant como fonte de verdade.** `X-Tenant`, `tenant_id` no corpo/query e afins são ignorados.

## 2. Fluxo do SPA

```text
GET  /sanctum/csrf-cookie                   → cookie XSRF-TOKEN
POST /api/v1/auth/login {email, password}   → sessão; 1 vínculo ativo ⇒ tenant selecionado automaticamente
POST /api/v1/session/tenant {tenant_uuid}   → seleção/troca explícita (audita tenant.selected)
GET  /api/v1/auth/me                        → usuário, vínculos ativos, tenant ativo, permissões nele
...  rotas de negócio (grupo tenant.required) com X-Tenant-Hint: <uuid do tenant exibido>
POST /api/v1/auth/logout
```

- O login só existe para **sessão** (exige `Origin`/`Referer` de um domínio stateful, `SANCTUM_STATEFUL_DOMAINS`); sem isso responde `403 stateful_required`.
- Resposta de credencial inválida é **idêntica** para e-mail inexistente e senha errada (`401 invalid_credentials`); uma verificação de hash sempre roda (tempo parecido).
- Usuário `blocked` com senha correta: `403 account_blocked`.
- Com **vários** vínculos o login **não** seleciona tenant; rotas de negócio respondem `403 tenant_required` até a seleção.
- Se a **auditoria** estiver indisponível no login, o login continua (melhor esforço), **sem** tenant ativo; a seleção explícita exige auditoria (falha fechada).

## 3. Validação em TODA requisição de negócio (`SetTenantContext`)

Grupo `tenant.required` = `auth:sanctum` → `user.active` → `tenant.context`:

1. resolve o tenant (sessão ou token); ausente → `403 tenant_required`;
2. usuário `blocked` → `403 account_blocked`;
3. vínculo `active` em `tenant_users` (consulta a cada requisição, **sem cache**); senão `403 tenant_forbidden`;
4. tenant `suspended`/`canceled` → `403 tenant_suspended`; removido (soft delete) → `403 tenant_forbidden`;
5. `X-Tenant-Hint` (opcional) ≠ tenant resolvido → `409 tenant_mismatch` **sem executar a operação**;
6. registra o `TenantContext`, sincroniza o Spatie (`setPermissionsTeamId`) e descarta relações de papéis em cache do usuário.

Rotas `auth/*` e `POST /session/tenant` ficam no grupo `authenticated` (sem tenant): nenhum dado de negócio.

## 4. Troca de tenant e abas

`POST /session/tenant` valida o **destino**, grava `tenant.selected` (origem → destino) **na transação**, atualiza `last_used_at`, e **só depois do commit** altera a sessão e **regenera o ID** (anti-fixação). Token de API não troca de tenant (`403 tenant_fixed`).

**Limitação conhecida:** a sessão guarda **um** tenant por navegador. Trocar na aba B faz a aba A operar no novo tenant na próxima requisição. Mitigações implementadas:

- `X-Tenant-Hint` enviado pelo cliente HTTP (`src/lib/http.ts`) → `409 tenant_mismatch` sem efeito colateral;
- `BroadcastChannel` (`src/lib/tenant-context.ts`) + `TenantSyncBanner`: a aba desatualizada é avisada e exige recarga.

Quem precisa operar vários tenants em paralelo deve usar **tokens** (cada um fixo no seu tenant).

## 5. Tokens de API

| Regra | Valor |
|---|---|
| Quem emite | Usuário com `tokens.manage`, **no seu tenant ativo** (`POST /api/v1/tokens`) |
| Tenant | Gravado na emissão, **imutável** (FK composta: o usuário precisa ter vínculo) |
| Expiração | **Obrigatória** (`expires_in_days` 1–90) + teto de segurança de 90 dias desde a criação (`sanctum.expiration`) |
| Valor | Exibido **uma única vez**; no banco, apenas o hash; nunca em auditoria nem em listagens |
| **Abilities (D-29)** | **Obrigatórias** (≥ 1), explícitas, do catálogo; **sem curinga** (`*` recusado na emissão e ignorado no uso); **nunca** permissões de gestão de acesso (`tokens.manage`, `roles.manage`, `members.manage`, `members.invite`); precisam caber nas permissões do emissor |
| Autorização do token | **abilities ∩ permissões vivas do usuário** no tenant (se o emissor perde o papel, o token perde junto) |
| Sem `tenant_id` | Acessa só `auth/*` — **nunca** dados de negócio (`403 tenant_required`), mesmo que tenha abilities |
| A cada uso | Revalida usuário ativo, vínculo ativo e tenant ativo |
| Revogação | `DELETE /tokens/{id}`; automática ao suspender/remover o vínculo, bloquear o usuário, redefinir a senha e no logout com o token |
| Expirado | `401`; a negação é auditada (`token.expired_use_denied`) |

Corpo da emissão: `POST /api/v1/tokens {"name": "...", "expires_in_days": 1..90, "abilities": ["tenant.view", "settings.view"]}`. Decisão completa em [DB_DECISIONS.md](DB_DECISIONS.md) D-29. Um teste estrutural garante que **toda** rota de negócio passa pelo `SetTenantContext`.

## 6. Recuperação de senha e sessões

- `POST /auth/forgot-password`: sempre `202` com a mesma mensagem; e-mail só para contas reais; link aponta para o **frontend** (`FRONTEND_URL`).
- `POST /auth/reset-password`: senha forte (mín. 12, maiúscula, minúscula, número); revoga **todos** os tokens; encerra as sessões antigas (hash da senha mudou).
- `POST /auth/logout-others`: exige a senha atual; regrava o hash e encerra as **outras** sessões (a atual continua). Audita `auth.sessions_revoked`.
- Sessões antigas caem pelo `AuthenticateSession` do **Sanctum**, que já integra a pilha stateful.
- Limites: `login` 5/min por e-mail+IP; `password-reset` e `invitations/accept` 3/min.

## 7. Erros relevantes

`401 unauthenticated · invalid_credentials` · `403 tenant_required · tenant_forbidden · tenant_suspended · tenant_fixed · account_blocked · stateful_required · privilege_escalation · forbidden` · `409 tenant_mismatch · last_admin` · `410 invitation_invalid · invitation_expired` · `422 validation_failed · invalid_reset_token` · `429 too_many_requests`. Formato em [API.md](API.md).

## 8. CORS

`config/cors.php`: origem = `FRONTEND_URL`, `supports_credentials = true`, cabeçalhos permitidos incluem `X-Tenant-Hint`, `X-XSRF-TOKEN` e `X-Request-Id`.

## 9. Testar o login pela API (sem expor a senha)

A senha é digitada **só por você**, em prompt oculto; nunca em argumento de comando, arquivo ou chat. Pré-requisito: `php artisan serve` em `obraflow-api` (http://localhost:8000). O login é por sessão e exige `Origin` de domínio stateful (`SANCTUM_STATEFUL_DOMAINS=localhost:5173`).

PowerShell:

```powershell
$base = "http://localhost:8000"; $h = @{ Origin = "http://localhost:5173"; Accept = "application/json"; Referer = "http://localhost:5173/" }
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Invoke-WebRequest "$base/sanctum/csrf-cookie" -Headers $h -WebSession $s | Out-Null
$xsrf = [uri]::UnescapeDataString(($s.Cookies.GetCookies($base) | ? Name -eq "XSRF-TOKEN").Value)
$pw = Read-Host "Senha" -AsSecureString
$plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($pw))
$body = @{ email = "barrosanderson@gmail.com"; password = $plain } | ConvertTo-Json
Invoke-RestMethod "$base/api/v1/auth/login" -Method Post -WebSession $s -Headers ($h + @{ "X-XSRF-TOKEN" = $xsrf }) -ContentType "application/json" -Body $body
Invoke-RestMethod "$base/api/v1/auth/me" -WebSession $s -Headers $h     # tenant ativo, papéis e permissões
```

Esperado: login `200` com o tenant `hightech` selecionado; `me` lista o papel `admin` e as 11 permissões. Senha errada ⇒ `401` genérico (igual a conta inexistente); mais de 5 tentativas/min ⇒ `429`.
