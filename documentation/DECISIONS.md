# DECISIONS.md — REGISTRO DE DECISÕES

Formato: ID · data · decisão · justificativa. Decisões de dados: [DB_DECISIONS.md](DB_DECISIONS.md).

| ID | Data | Decisão | Justificativa |
|---|---|---|---|
| A-01 | 2026-11-12 | **Identidade visual oficial: azul-safira** (`#0F52BA`), sem laranja de destaque | Definida nos PROMPTS 002/003 e na "Paleta Oficial"; tokens centralizados em `obraflow-web/src/index.css` |
| A-02 | 2026-11-12 | Laravel **13** / PHP 8.4 | Versão estável atual; requisito PHP ^8.3 |
| A-03 | 2026-11-12 | Redis via **Predis** | `phpredis` não instalado no ambiente; Predis é puro PHP e estável |
| A-04 | 2026-11-12 | Testes backend com **Pest** | Sintaxe concisa sobre PHPUnit 12 (compatível) |
| A-05 | 2026-11-12 | Tailwind **v4** + shadcn/ui | Versão atual; tokens via CSS variables |
| A-06 | 2026-11-12 | Estrutura modular `app/Modules/*` e rotas `routes/api/v1/*.php` | Módulos independentes ([AGENTS.md](AGENTS.md)) |
| A-07 | 2026-11-12 | Migrations padrão do Laravel **removidas** | PROMPT 001 §4 e 004: nenhuma migration antes da aprovação; `users` será global |
| A-08 | 2026-11-12 | Gráficos com Recharts; ícones Lucide | Único par adicional necessário; Lucide exigido pelo PROMPT 003 |
| A-09 | 2026-11-12 | Modal próprio sobre `<dialog>` nativo e toast próprio | Evita dependência extra; acessibilidade nativa (foco, ESC) |
| A-10 | 2026-11-12 | **Laravel Boost não instalado** | O `CLAUDE.md` do skeleton o sugere, mas não foi solicitado e o PROMPT 001 veda dependências sem justificativa |
| A-11 | 2026-11-12 | Vitest em `vitest.config.ts` separado do Vite | Testes de lógica pura não precisam dos plugins de build |
| A-12 | 2026-11-12 | Tenant/`users` global, FKs compostas, Spatie Teams | Ver [DB_DECISIONS.md](DB_DECISIONS.md) D-01…D-05 |
| A-13 | 2026-11-12 | **D-P01 aprovada**: sessão no servidor (SPA) + token vinculado ao tenant (API); `X-Tenant` nunca é autoridade; revalidação por requisição; troca auditada | Aprovação do revisor após [FASE1_DECISOES_PENDENTES.md](FASE1_DECISOES_PENDENTES.md) |
| A-14 | 2026-11-12 | **D-P02 e D-P03 adiadas** para a Fase 4; recomendações mantidas; fórmula de BDI e incidência do desconto **não** aprovadas | Dependem de regras de negócio e contábeis |
| A-15 | 2026-11-12 | Escopo da Fase 1 = **15 tabelas** (com `audit_logs`); sem billing, empresas, obras ou módulos | Billing → Fase 10 |
| A-16 | 2026-11-12 | Sem papéis globais; integridade do Spatie por FK composta; token sem tenant não acessa negócio; auditoria de acesso; **sem triggers** | [DB_DECISIONS.md](DB_DECISIONS.md) D-18…D-23 |
| A-17 | 2026-11-12 | Modelo da Fase 1 **validado em MySQL 8.0.30** (instância descartável, 87/87) | [validacao-fase1/](validacao-fase1/README.md) |
| A-18 | 2026-11-12 | Roadmap renumerado (consolidação de fases) | [ROADMAP.md](ROADMAP.md), [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md) |
| A-19 | 2026-11-12 | **Fase 1 autorizada e implementada** (aprovação formal; ajustes A1–A14 aprovados) | [FASE1_MODELO.md](FASE1_MODELO.md), [FASE1_RELATORIO.md](FASE1_RELATORIO.md) |
| A-20 | 2026-11-12 | Banco `obraflow`/`obraflow_testing` + usuário exclusivo `obraflow_app` (`caching_sha2_password`) no MySQL do Laragon; conexão em `+00:00` | [AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md) |
| A-21 | 2026-11-12 | **Redis adiado**: drivers `file`/`file`/`sync`; health só exige Redis quando algum driver o usa | [AMBIENTE_LOCAL.md](AMBIENTE_LOCAL.md) §4 |
| A-22 | 2026-11-12 | Tokens: expiração obrigatória, máximo 90 dias (+ teto `sanctum.expiration`) | [AUTENTICACAO.md](AUTENTICACAO.md) §5 |
| A-23 | 2026-11-12 | `X-Tenant-Hint` e `BroadcastChannel` implementados (frontend) | [AUTENTICACAO.md](AUTENTICACAO.md) §4 |
| A-24 | 2026-11-12 | Provisionamento só por CLI protegido; sem interface de superadmin | [PROVISIONAMENTO.md](PROVISIONAMENTO.md) |
| A-25 | 2026-11-12 | Decisões de implementação D-24…D-29 (Role sem escopo global, último admin com lock, ordem banco→sessão, AuthenticateSession do Sanctum, convites, abilities pendentes) | [DB_DECISIONS.md](DB_DECISIONS.md) |
| A-26 | 2026-11-12 | `documentation/telas_modelos.zip` e os `.zip` do SINAPI foram removidos pelo usuário (duplicavam as pastas); as pastas SINAPI de MT (2024-12, Desonerado/Não Desonerado) permanecem como **referência**, sem importação | — |

## Divergências registradas

| # | Divergência | Resolução |
|---|---|---|
| 1 | O DATABASE.md original colocava `tenant_id` em `users`, `roles` e `user_*`; o PROMPT 004 define `users` global | DATABASE.md **reescrito** conforme o PROMPT 004 (mais recente). Aguarda aprovação |
| 2 | Os PROMPTS 002/003 citam `documentation/references/telas/`; as imagens estão em `documentation/telas/` | Documentação usa o caminho real; nada foi movido |
| 3 | O PROMPT 001 pede validar ambientes com Docker; **Docker não está instalado** nesta máquina | Arquivos escritos e revisados, **não executados**; ver pendências no README |
| 4 | O PROMPT 002 estava truncado em "### Refer" | Corrigido pelo usuário; relido na íntegra |
