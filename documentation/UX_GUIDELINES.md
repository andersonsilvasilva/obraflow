# UX_GUIDELINES.md

Público: profissionais que lidam diariamente com dados técnicos, financeiros e operacionais. Prioridade: **produtividade, legibilidade e rapidez**.

## 1. Navegação

- Sidebar agrupada (Dashboard · Gestão · Engenharia · Operação · Administração · Configurações); item ativo em azul-safira sobre fundo grafite; recolhível.
- Topbar compacta: organização ativa, seletor de empresa e de obra (quando aplicável), busca global, notificações, ajuda, perfil.
- Cada página: **breadcrumb → título → descrição curta → ações contextuais → conteúdo**.
- Rotas em português e previsíveis (`/orcamentos/:id/preencher`, `/finalizacao`).

## 2. Hierarquia visual

1. Título e ação primária (botão azul, um por contexto).
2. Indicadores (cartões) no topo.
3. Filtros/ferramentas acima da tabela.
4. Conteúdo denso em painéis brancos sobre fundo cinza claro.
Ação destrutiva nunca é primária; use `ConfirmDialog`.

## 3. Formulários

- Rótulo sempre visível; obrigatórios com `*`; ajuda curta abaixo do campo.
- Validação com Zod em campo e no envio; mensagem objetiva junto ao campo; foco no primeiro erro.
- Agrupar por assunto (dados gerais, valores, observações); 2 colunas no desktop, 1 no mobile.
- Campos opcionais não obrigam; padrões sensatos.
- Moeda sempre `R$` com 2 casas; datas `dd/mm/aaaa` na apresentação.

## 4. Tabelas

- Cabeçalho fixo semanticamente (`th scope`), ordenação nas colunas relevantes, paginação com total.
- Números alinhados à direita com `tabular-nums`; status como `StatusBadge`.
- Filtros e busca acima; botão "Limpar".
- Linha clicável apenas quando há ação óbvia; ações explícitas na coluna "Ações" com `aria-label`.
- Vazio: explicar e oferecer ação. Muitos dados: rolagem horizontal controlada.

## 5. Modais e painéis

- **Modal centralizado**: confirmações e formulários curtos.
- **Painel lateral**: detalhamento (composição de custos) e cadastro rápido sem perder o contexto.
- ESC e clique fora fecham; foco devolvido ao acionador; título descreve a ação.

## 6. Feedback

- Sucesso: toast (5 s). Erro de operação: toast `error` ou `ErrorState` com **Tentar novamente**.
- Carregamento: `LoadingState`. Ações longas: desabilitar botão e indicar progresso.
- Mensagens em linguagem de negócio, sem códigos técnicos.

## 7. Honestidade do produto

- Telas com dados fictícios exibem **"Dados de demonstração"**.
- Módulos não construídos exibem **"em preparação"** — não simulam funcionalidade.
- Cálculos demonstrativos avisam que **não são oficiais**.
- IA: sugestões rotuladas, **sempre** com aceitar/rejeitar humano; nunca "validado automaticamente".

## 8. Responsividade e acessibilidade

Ver [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) §7–8. Teclado em tudo, foco visível, contraste AA, textos alternativos.
