# ROADMAP.md

> Ordem proposta; **cada fase exige aprovação expressa** antes de iniciar ([AGENTS.md](AGENTS.md)). A numeração é a mesma de [DB_MIGRATION_PLAN.md](DB_MIGRATION_PLAN.md).

| Fase | Entrega | Estado |
|---|---|---|
| **0 — Fundação** | API Laravel, SPA React, infraestrutura, CI, documentação, identidade visual, protótipo navegável | ✅ |
| **0.5 — Modelo de dados** | Modelagem completa ([DATABASE.md](DATABASE.md)) | ✅ proposta entregue |
| **1 — Identidade, tenancy e acesso** | 15 tabelas ([FASE1_MODELO.md](FASE1_MODELO.md)); autenticação Sanctum (SPA + token por tenant); seleção e troca de tenant; Spatie Teams sem papéis globais; auditoria de acesso; testes de isolamento. **Decisões D-P01 aprovada.** | ✅ **Implementada** (15 migrations, autenticação, tenancy, Spatie Teams, convites, provisionamento, auditoria; 180 + 14 testes). **Homologada** (CI verde em MySQL 8.0/8.4) — [FASE1_RELATORIO.md](FASE1_RELATORIO.md) |
| **2 — Cadastros** | Unidades, empresas, filiais, clientes, fornecedores, contatos/endereços, documentos (disco privado + URL assinada; S3 por configuração) | 🔨 **Em andamento** — modelo aprovado ([FASE2_MODELO.md](FASE2_MODELO.md)) |
| **3 — Equipes e Obras** | Funcionários, equipamentos, obras, membros, fases, acesso por obra | ⏳ |
| **4 — Referências e Orçamentos (prioritário)** | Bases técnicas (SINAPI/SUDECAP) importadas com rastreio; versões, composições, BDI, encargos, Curva ABC, aprovação, importações. **Só após D-P02 e D-P03** | ⏳ |
| **5 — Contratos e Medições** | Contratos, aditivos, medições com aprovação | ⏳ |
| **6 — Planejamento e Diário** | Cronograma, dependências, linha de base, diário de obras, ponto, alocações | ⏳ |
| **7 — Estoque e Compras** | Solicitação → Cotação → Pedido → Recebimento; movimentações de estoque | ⏳ |
| **8 — Financeiro** | Títulos, movimentações, conciliação, fluxo de caixa | ⏳ |
| **9 — IA assistiva** | Orçamento inteligente com revisão humana, trilha e limites de uso | ⏳ |
| **10 — Plataforma** | Planos, assinaturas, cobrança, MFA, notificações, observabilidade, administração de plataforma, hardening de produção | ⏳ |

## Decisões pendentes de negócio

| ID | Estado | Quando |
|---|---|---|
| D-P01 tenant ativo | **Aprovada** (híbrido) | — |
| D-P02 BDI e custos indiretos | Adiada (recomendação mantida; fórmula e incidência do desconto **não** aprovadas) | Início da Fase 4 |
| D-P03 encargos sociais | Adiada (`with_relief`/`without_relief`/`not_applicable`; sem cálculo próprio no MVP) | Início da Fase 4 |
| D-P04…D-P10 | Em aberto ([DB_DECISIONS.md](DB_DECISIONS.md)) | Conforme a fase |

## Pendências transversais

TLS/CSP/backup; rate limiting; relatórios/exportações; internacionalização; retenção e particionamento de auditoria (D-P06); política LGPD (D-P07).
