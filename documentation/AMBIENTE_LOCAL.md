# AMBIENTE_LOCAL.md — Laragon: MySQL, drivers e adoção futura do Redis

## 1. MySQL (executado em 2026-11-12, autorizado)

Servidor do Laragon (MySQL 8.0.30). Foram criados **somente**:

```sql
CREATE DATABASE IF NOT EXISTS obraflow         CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS obraflow_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'obraflow_app'@'localhost' IDENTIFIED WITH caching_sha2_password BY '<gerada>';
CREATE USER IF NOT EXISTS 'obraflow_app'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY '<gerada>';
GRANT ALL PRIVILEGES ON obraflow.*         TO 'obraflow_app'@'localhost', 'obraflow_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON obraflow_testing.* TO 'obraflow_app'@'localhost', 'obraflow_app'@'127.0.0.1';
```

Resultado verificado: o usuário enxerga apenas `obraflow` e `obraflow_testing` (outros bancos do servidor não são visíveis nem alterados); sessão em `+00:00`; collation `utf8mb4_0900_ai_ci`. A senha foi gerada aleatoriamente e está **somente** no `.env` local (ignorado pelo Git).

Em produção: o usuário da aplicação deve ter, em `audit_logs`, apenas `SELECT, INSERT` (imutabilidade — validado em [validacao-fase1/](validacao-fase1/README.md)); migrations rodam com outro usuário.

## 2. Configuração aplicada no código

- `config/database.php` (mysql): `timezone => '+00:00'`, `engine => InnoDB`, collation `utf8mb4_0900_ai_ci`. Sem isso o MySQL com `time_zone=SYSTEM` deslocaria `TIMESTAMP` (provado em teste).
- `phpunit.xml`: força `DB_DATABASE=obraflow_testing`.

## 3. Drivers locais (Redis adiado)

| Função | Driver local | Variável |
|---|---|---|
| Sessão | `file` (`storage/framework/sessions`) | `SESSION_DRIVER=file` |
| Cache (inclui cache de permissões do Spatie e *rate limiting*) | `file` | `CACHE_STORE=file` |
| Fila | `sync` (sem tabela `jobs`; não há jobs assíncronos na Fase 1) | `QUEUE_CONNECTION=sync` |
| E-mail | `log` (`storage/logs`) | `MAIL_MAILER=log` |
| Health `/ready` | Verifica **só** o MySQL; o Redis só entra se algum driver acima for `redis` | — |

## 4. Como adotar o Redis depois

1. Instalar/subir o Redis (Laragon traz `redis-x64`) e definir senha.
2. `.env`: `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST/PORT/PASSWORD`, `REDIS_PREFIX=obraflow-` (o cliente **Predis** já está instalado; não precisa da extensão `phpredis`).
3. `php artisan config:clear`. O `/api/v1/health/ready` passa a exigir o Redis automaticamente.
4. **Filas:** rodar `php artisan queue:work`. Jobs futuros devem restaurar o `TenantContext` (usar `TenantContext::run`) — ainda não há jobs.
5. **Cache do Spatie é global** (um registro): chaves de cache de negócio devem ser prefixadas por tenant (`t:{id}:…`).
6. **Sessões:** a invalidação de sessões antigas (troca de senha) funciona igual no Redis.
7. Docker Compose já usa Redis (`infrastructure/docker-compose.yml`).

Nada no código muda além do `.env`; os testes continuam usando `file`/`sync`.
