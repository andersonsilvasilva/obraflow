# PROJECT.md — OBRAFLOW

**ObraFlow** é um ERP SaaS multi-tenant para construção civil, desenvolvido pela **HighTech Tecnologia**.

- **Segmento:** gestão empresarial e operacional da construção civil.
- **Posicionamento:** plataforma moderna, profissional e inteligente para construtoras, obras, contratos, orçamentos e recursos.
- **Público:** construtoras, incorporadoras, engenheiros, arquitetos, gestores de obra, orçamentistas, compradores e equipes administrativo-financeiras.
- **Identidade visual oficial:** **azul-safira** (decisão registrada em [DECISIONS.md](DECISIONS.md) e [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)).

## Escopo funcional previsto

Empresas · Clientes · Fornecedores · Obras · Contratos · **Orçamentos (módulo prioritário)** · Planejamento · Diário de Obras · Medições · Financeiro · Compras · Suprimentos · Estoque · Equipes · Documentos · Relatórios · Configurações · Orçamento inteligente (IA assistiva, sempre com aprovação humana).

## Estado atual

| Prompt | Entrega | Situação |
|---|---|---|
| 001 | Fundação (API, web, infra, docs) | ✅ Concluída (ver README e ROADMAP) |
| 002 | Identidade visual e Design System | ✅ Concluída (tokens, componentes, docs) |
| 003 | Protótipo navegável | ✅ Concluído (dados demonstrativos, sem backend) |
| 004 | Modelagem de dados | ✅ Proposta completa entregue ([DATABASE.md](DATABASE.md)); as demais fases seguem sem migrations |
| **Fase 1** | Identidade, tenancy, autenticação e acesso | ✅ **Implementada** ([FASE1_RELATORIO.md](FASE1_RELATORIO.md)); MySQL 8.4 pendente no CI |

## Estrutura do repositório

```text
obraflow/
├── obraflow-api/      Laravel 13 (PHP 8.4) — API REST /api/v1
├── obraflow-web/      React 19 + TypeScript + Vite
├── infrastructure/    Docker Compose, Nginx
├── documentation/     Documentação, prompts, referências de tela
└── .github/workflows/ CI
```

## Princípios

1. Segurança, integridade dos dados e **isolamento multi-tenant** acima de tudo ([AGENTS.md](AGENTS.md)).
2. Nada de dados fictícios que pareçam oficiais; nada de valores de referência inventados.
3. IA apenas sugere; decisões técnicas e financeiras são humanas e auditáveis.
4. Mudanças estruturais só por migrations aprovadas.
