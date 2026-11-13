# PROVISIONAMENTO.md — Provisionamento inicial e operação via CLI

Sem interface de superadmin nesta fase (decisão da aprovação da Fase 1). A administração da plataforma é feita por comandos Artisan, **somente no console**.

## 1. Instalação do zero

```bash
cd obraflow-api
cp .env.example .env && php artisan key:generate        # preencha DB_PASSWORD (usuário exclusivo, nunca root)
php artisan migrate                                       # as 15 tabelas da Fase 1
php artisan obraflow:provision --name="Minha Construtora" --admin-email=admin@empresa.com --admin-name="Fulano"
```

O catálogo de permissões é instalado pelo próprio provisionamento (idempotente). Para reinstalá-lo/atualizá-lo: `php artisan obraflow:sync-permissions`.

## 2. `obraflow:provision`

Cria, **numa única transação**: tenant ativo, administrador (ou vincula um existente), os 3 papéis de sistema (`admin`, `member`, `viewer`, não excluíveis nem editáveis), o vínculo e a atribuição do papel `admin`. Audita `tenant.provisioned`, `membership.created`, `role.created`, `permission.granted` e `role.assigned`.

| Opção | Efeito |
|---|---|
| `--name` / `--slug` / `--admin-email` / `--admin-name` | Dados (faltando, pergunta no modo interativo). Slug padrão = derivado do nome |
| `--generate-password` | Gera senha forte (20 caracteres) e a exibe **uma única vez** — para execução sem prompt |
| `--existing-admin` | Permite vincular um usuário que **já existe** como administrador do novo tenant (não duplica a conta) |
| `--allow-duplicate-name` | Permite repetir o nome de um tenant existente |
| `--yes` | Não pede confirmação |
| `--force` | **Obrigatório em produção** |

**Proteções:** existe só no console · senha **nunca** por argumento (prompt oculto com confirmação, ou `--generate-password`) · mostra resumo e pede confirmação · recusa slug duplicado (inclusive de tenant removido, pois o slug fica reservado) · recusa nome duplicado e administrador já existente sem a flag explícita · recusa usuário bloqueado · senha precisa ser forte · atômico: falha no meio não deixa nada pela metade · rodar duas vezes com os mesmos dados **não** cria nada novo.

## 3. Outros comandos

| Comando | Para quê |
|---|---|
| `obraflow:user:block {email} [--force]` | Bloqueia o usuário (revoga tokens; perde sessão na próxima requisição). **Recusa** se for o último administrador de algum tenant, salvo `--force`. Audita `auth.user_blocked` |
| `obraflow:user:unblock {email}` | Desbloqueia (tokens revogados não voltam). Audita `auth.user_unblocked` |
| `obraflow:tenant:status {slug} {active\|suspended\|canceled}` | Suspende/cancela/reativa; vale na próxima requisição. Audita `tenant.suspended`/`tenant.reactivated` |
| `obraflow:invitations:expire` | Expira convites vencidos (**agendado de hora em hora**; audita `invitation.expired`). Requer o agendador (`php artisan schedule:work` / cron `schedule:run`) |
| `obraflow:sync-permissions` | Instala/atualiza o catálogo global de permissões (nunca apaga) |

## 4. Papéis de sistema

| Papel | Permissões |
|---|---|
| `admin` | todas (`tenant.view/update`, `members.view/invite/manage`, `roles.view/manage`, `settings.view/manage`, `tokens.manage`, `audit.view`) |
| `member` | `tenant.view`, `members.view`, `roles.view`, `settings.view` |
| `viewer` | `tenant.view` |

Regras: ninguém concede o que não possui (`403 privilege_escalation`); o **último administrador ativo** (vínculo ativo + usuário ativo + papel `admin`) é protegido sob *lock* de linha no tenant.

## 5. Senha gerada

É exibida **uma vez** no terminal e nunca gravada em log nem em auditoria. Guarde em local seguro e peça a troca no primeiro acesso (fluxo `forgot-password`/`reset-password`).

## Pendência: dados cadastrais do tenant HighTech

**Atualização 2026-11-13:** CNPJ gravado (`61693043000194`, autorizado, evento `tenant.updated`). Razão social gravada em 2026-11-13 (`HIGHTECH TECNOLOGIA CONSULTORIA E DESENVOLVIMENTO LTDA`, autorizada, evento `tenant.updated`). **Pendência encerrada.**

Originalmente, razão social (`tenants.legal_name`) e CNPJ (`tenants.document_number`) do tenant `hightech` estavam **vazios de propósito**: o comando `obraflow:provision` não os recebe e **não foram inventados**. Para preenchê-los é preciso (1) os valores reais, fornecidos pelo responsável, e (2) autorização expressa para alterar o tenant inicial (via novas opções do comando ou rotina de atualização cadastral). Nada foi alterado no banco.
