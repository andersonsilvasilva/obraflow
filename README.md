# ObraFlow

ERP SaaS multi-tenant para construção civil — HighTech Tecnologia. Documentação em [`documentation/`](documentation/PROJECT.md).

```text
obraflow/
├── obraflow-api/      Laravel 13 · PHP 8.4
├── obraflow-web/      React 19 · TypeScript · Vite 8 · Tailwind 4
├── infrastructure/    Docker Compose · Nginx
├── documentation/
└── .github/workflows/ci.yml
```

## Requisitos

PHP ≥ 8.3 (testado 8.4) com `pdo_mysql`, `intl`, `mbstring`, `zip`, `bcmath` · Composer 2 · Node ≥ 22 · MySQL 8.x · Redis · (opcional) Docker + Compose.

## Backend (local)

```bash
cd obraflow-api
composer install
cp .env.example .env            # preencha DB_PASSWORD etc. — nunca versione o .env
php artisan key:generate
# crie o banco "obraflow" no MySQL (nenhuma migration existe ainda — aguardam aprovação do modelo)
php artisan serve               # http://localhost:8000
curl http://localhost:8000/api/v1/health
```

Testes e estilo:

```bash
vendor/bin/pest
vendor/bin/pint --test
```

`GET /api/v1/health/ready` retorna `503` enquanto MySQL/Redis não estiverem acessíveis (comportamento esperado).

## Frontend (local)

```bash
cd obraflow-web
npm install
cp .env.example .env.local
npm run dev                     # http://localhost:5173 (dados demonstrativos)
npm run lint
npm test
npm run build
```

> **Windows + Git Bash:** execute `npm test` pelo **PowerShell/CMD**. No Git Bash o Vitest falha com `Cannot read properties of undefined (reading 'config')` por diferença de caixa/caminho (`/c/...` × `C:/...`).

## Containers

```bash
cd infrastructure
cp .env.example .env            # preencha APP_KEY, MYSQL_*, REDIS_PASSWORD
docker compose up -d --build    # http://localhost:8080
docker compose ps               # todos devem ficar "healthy"
```

⚠️ Os arquivos de infraestrutura foram escritos e revisados, mas **não foram executados** (Docker não está instalado na máquina de desenvolvimento).

## Primeiro uso do backend (Fase 1)

```bash
cd obraflow-api
php artisan migrate                                    # 15 tabelas (banco 'obraflow', usuário exclusivo — ver documentation/AMBIENTE_LOCAL.md)
php artisan obraflow:provision --name="Minha Construtora" --admin-email=admin@empresa.com --admin-name="Fulano"
vendor/bin/pest                                        # 198 testes (usa o banco 'obraflow_testing')
```

## Estado

- **Fase 1 implementada:** identidade global, tenancy, autenticação (sessão + tokens por tenant), Spatie Teams, convites, provisionamento CLI e auditoria — [FASE1_RELATORIO.md](documentation/FASE1_RELATORIO.md).
- Sem módulos de negócio (empresas, obras, orçamentos… são fases futuras).
- O frontend usa dados **demonstrativos** (`VITE_USE_MOCKS=true`) e ainda **não** tem tela de login.
- Redis adiado: drivers locais (`file`/`sync`). MySQL 8.4 a comprovar no CI.
