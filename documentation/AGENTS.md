# AGENTS.md — INSTRUÇÕES PARA AGENTES DE DESENVOLVIMENTO

## Identidade do projeto

Você está trabalhando no ObraFlow, uma plataforma SaaS multi-tenant de gestão para construção civil, desenvolvida pela HighTech Tecnologia.

Seu objetivo é implementar funcionalidades com qualidade de produção, segurança, modularidade e aderência à arquitetura documentada.

## 1. Antes de qualquer implementação

Leia os seguintes documentos, quando existentes:

- PROJECT.md
- ARCHITECTURE.md
- DATABASE.md
- MULTITENANCY.md
- SECURITY.md
- BUSINESS_RULES.md
- API.md
- DESIGN_SYSTEM.md
- ROADMAP.md

Não invente decisões arquiteturais quando elas já estiverem documentadas.

## 1.1 Decisões oficiais já registradas

- **Identidade visual: azul-safira** (`#0F52BA`), tokens em `obraflow-web/src/index.css`; sem laranja de destaque; sem hexadecimais nos componentes ([DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)).
- **`users` é global** (sem `tenant_id`); vínculo por `tenant_users`; Spatie Teams com `team_foreign_key = tenant_id` ([MULTITENANCY.md](MULTITENANCY.md)). *O modelo de dados está **aguardando aprovação** — não criar migrations antes dela.* **Sem papéis globais** (todo papel tem `tenant_id`); **token sem `tenant_id` nunca acessa dados de negócio**; `X-Tenant` nunca é autoridade; **sem triggers**; recarregar o usuário/limpar relações ao trocar o tenant do contexto (armadilha confirmada, [FASE1_MODELO.md](FASE1_MODELO.md) §7.1).
- Frontend em dados demonstrativos (`src/mocks`), acessados só por `src/services`. Dados fictícios usam o prefixo `DEMO-` e nunca imitam fontes oficiais.
- Ambiente Windows/Git Bash: rodar `npm test` pelo PowerShell/CMD (ver README).
- Registro de decisões: [DECISIONS.md](DECISIONS.md) e [DB_DECISIONS.md](DB_DECISIONS.md).

## 2. Regras de segurança

- Nunca confiar no tenant_id enviado pelo frontend.
- Identificar o tenant pelo contexto autenticado e validado no backend.
- Não permitir consultas sem escopo de tenant nas entidades operacionais.
- Validar empresa e obra em todos os relacionamentos.
- Aplicar Policies nas operações protegidas.
- Não expor documentos privados por URLs públicas permanentes.
- Não registrar senhas, tokens ou segredos em logs.
- Não armazenar credenciais em código-fonte.
- Testar tentativas de acesso cruzado entre tenants.

## 3. Backend Laravel

Utilize:

- Controllers para comunicação HTTP.
- Form Requests para validação.
- Services ou Actions para casos de uso.
- Models para persistência.
- Policies para autorização.
- API Resources para serialização.
- Jobs para processamento assíncrono.
- Events e Listeners para eventos de domínio quando apropriado.

Não concentre regras de negócio complexas nos Controllers.

Evite abstrações desnecessárias e mantenha os módulos independentes.

## 4. Frontend React

Utilize:

- TypeScript com strict mode.
- Componentes reutilizáveis.
- Separação por módulos.
- TanStack Query para dados remotos.
- React Hook Form e Zod para formulários.
- Tratamento centralizado de erros da API.
- Estados de carregamento, vazio e erro.
- Componentes responsivos.
- Acessibilidade.

Não duplicar componentes ou regras de apresentação sem justificativa.

## 5. Banco de dados

- Utilizar migrations.
- Criar índices apropriados.
- Definir chaves estrangeiras.
- Preservar integridade referencial.
- Utilizar DECIMAL para valores monetários.
- Utilizar UUID quando necessário para sincronização e identificadores públicos.
- Utilizar transações para operações críticas.
- Evitar exclusões físicas de registros financeiros e históricos.
- Documentar relacionamentos e regras especiais.

## 6. Testes obrigatórios

Para cada módulo:

- Testes unitários.
- Testes de integração.
- Testes de autorização.
- Testes de isolamento multi-tenant.
- Testes de validação.
- Testes dos fluxos críticos.

Não considerar a tarefa concluída se os testes falharem.

## 7. Processo de trabalho

Para cada tarefa:

1. Analisar o pedido.
2. Consultar a documentação.
3. Identificar dependências.
4. Apresentar plano de execução.
5. Implementar a funcionalidade.
6. Executar testes.
7. Corrigir erros.
8. Atualizar documentação.
9. Informar arquivos alterados.
10. Apresentar resultado e pendências.

## 8. Restrições

- Não alterar módulos não relacionados sem autorização.
- Não remover funcionalidades existentes silenciosamente.
- Não ignorar erros de TypeScript ou PHP.
- Não desabilitar testes para obter aprovação.
- Não inserir dados fictícios em produção.
- Não inventar integrações externas.
- Não expor dados de clientes à inteligência artificial sem autorização e controle.
- Não implementar operações financeiras irreversíveis sem trilha de auditoria.

## 9. Comunicação

Sempre apresentar:

- O que será implementado.
- Quais arquivos serão criados ou alterados.
- Quais migrations serão executadas.
- Quais testes serão realizados.
- Quais decisões técnicas foram tomadas.
- Quais pendências permanecem.

**Prioridade absoluta:** segurança, integridade dos dados, isolamento multi-tenant e consistência das regras de negócio.