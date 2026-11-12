# PROMPT 001 — INICIALIZAÇÃO DO OBRAFLOW

Você atuará como arquiteto de software e engenheiro full-stack responsável pela fundação técnica do ObraFlow.

O ObraFlow é um ERP SaaS multi-tenant para construção civil, desenvolvido pela HighTech Tecnologia.

## OBJETIVO DESTA TAREFA

Preparar a estrutura inicial dos repositórios backend e frontend, com documentação, ambientes e padrões de desenvolvimento.

Não implementar ainda os módulos funcionais de negócio.

## STACK

Backend:
- Laravel
- PHP
- MySQL
- Redis
- Laravel Sanctum
- Spatie Laravel Permission

Frontend:
- React
- TypeScript
- Vite
- Tailwind CSS
- shadcn/ui
- React Router
- TanStack Query
- React Hook Form
- Zod

Infraestrutura:
- Docker
- Docker Compose
- Nginx
- GitHub Actions

## ETAPA 1 — ESTRUTURA

Criar:

obraflow/
- obraflow-api/
- obraflow-web/
- infrastructure/
- documentation/

Preparar os projetos com configurações apropriadas.

## ETAPA 2 — BACKEND

Inicializar Laravel.

Preparar:
- Configuração MySQL.
- Redis.
- Sanctum.
- Spatie Permission.
- Estrutura modular.
- API versionada em /api/v1.
- Tratamento padronizado de erros.
- Health check.
- Testes automatizados.

## ETAPA 3 — FRONTEND

Inicializar React com Vite e TypeScript.

Configurar:
- Tailwind CSS.
- shadcn/ui.
- React Router.
- TanStack Query.
- React Hook Form.
- Zod.
- Estrutura modular.
- Tratamento de erros.
- Configuração de variáveis de ambiente.

Criar layout inicial com sidebar, cabeçalho e área de conteúdo.

## ETAPA 4 — INFRAESTRUTURA

Criar Docker Compose com:
- Backend.
- Frontend.
- MySQL.
- Redis.
- Nginx.

Preparar volumes persistentes, health checks e variáveis de ambiente.

Não colocar senhas ou tokens diretamente nos arquivos versionados.

## ETAPA 5 — DOCUMENTAÇÃO

Criar:
- PROJECT.md
- AGENTS.md
- ARCHITECTURE.md
- DATABASE.md
- SECURITY.md
- MULTITENANCY.md
- API.md
- ROADMAP.md

Utilizar os documentos de referência fornecidos como base.

## REGRAS

1. Não implementar módulos de negócio ainda.
2. Não inventar novas tecnologias sem justificativa.
3. Utilizar versões estáveis e compatíveis.
4. Não criar migrations operacionais antes da aprovação do modelo de dados.
5. Configurar testes.
6. Validar a execução dos ambientes.
7. Documentar comandos de instalação.
8. Não inserir credenciais reais.
9. Não utilizar versões obsoletas de dependências.
10. Apresentar relatório final com arquivos criados e testes executados.

Ao concluir, apresentar:
- Estrutura de diretórios.
- Versões utilizadas.
- Comandos para iniciar.
- Resultado dos testes.
- Pendências.
- Próximos passos recomendados.

Não avançar para o desenvolvimento dos módulos sem autorização.