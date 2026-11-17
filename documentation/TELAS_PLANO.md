# ETAPA DAS TELAS — LOGIN E CADASTROS (FASES 1–3)

> Autorizada pelo responsável em 2026-11-16 ("pode começar"). Telas no **padrão de layout normativo** ([SCREEN_REFERENCES.md](SCREEN_REFERENCES.md) §"Padrão de layout e apresentação") com a identidade do ObraFlow ([DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)). Ao final: subir API e frontend para teste no navegador (pedido do responsável).

## Sub-etapas

| # | Entrega |
|---|---|
| T1 | Integração real com a API: sessão Sanctum (cookie + CSRF), login, seleção/troca de organização, sair, proteção de rotas, permissões do usuário; moldura G1–G5 (sidebar com organização no topo, grupos em até 3 níveis, cartão do usuário no rodapé) |
| T2 | Componentes do padrão (listagem L1–L6, modal F1–F3, confirmação) e cadastros da Fase 2: empresas, clientes, fornecedores, unidades |
| T3 | Fase 3: cargos, setores, funcionários (+ certificações), grupos e equipamentos; obras (lista + detalhe: dados, endereço, fases, equipe, status/histórico, acesso) |
| T4 | Testes (Vitest), build, lint, documentação; subir os servidores |

## Decisões de integração

- **T-01 — API real × demonstração:** os módulos com backend (Fases 1–3) usam **sempre** a API. As telas-protótipo sem backend (dashboard, orçamentos, suprimentos) continuam com dados demonstrativos, marcadas como **"Demonstração"** no menu; `VITE_USE_MOCKS` passa a valer só para elas.
- **T-02 — Autenticação:** somente sessão por cookie (Sanctum SPA), com `GET /sanctum/csrf-cookie` e cabeçalho `X-XSRF-TOKEN` nas escritas. Nenhum token é guardado no navegador. A organização ativa vem do servidor (`/auth/me`); o `X-Tenant-Hint` continua só como dica.
- **T-03 — Permissões na interface:** o menu mostra só o que o usuário pode consultar; ações sem permissão aparecem **desabilitadas** (padrão, ajuste 6). A API continua sendo a autoridade (403/404).
- **T-04 — Dados:** nada de dados fictícios no banco real; a tela vazia mostra o estado vazio com "Adicionar".

## Andamento (2026-11-16)

**T1–T4 entregues num só bloco** (login, moldura, cadastros das Fases 2 e 3, obras).

- **Autenticação:** `src/features/auth` (sessão, `/auth/me`, login com "Esqueci minha senha", escolha e troca de organização, sair, proteção de rotas e por permissão). `src/lib/http.ts` busca `/sanctum/csrf-cookie`, envia `X-XSRF-TOKEN` nas escritas, renova o token uma vez em 419 e volta ao login em 401.
- **Moldura (G1–G5):** sidebar com a organização no topo (troca quando houver mais de uma), grupos em até 3 níveis (Cadastros → Equipes / Recursos), cartão do usuário com "Sair" no rodapé, modo recolhido só com ícones; barra superior apenas em telas estreitas. Menu filtrado por permissão; telas-protótipo marcadas "Demo"; módulos sem backend em "Em preparação".
- **Base genérica** `src/features/registry/components`:
  - `ResourcePage` (L1–L6): busca e filtros à esquerda e "+ Adicionar" à direita; tabela com cabeçalho fixo e ordenação; ações por linha; paginação com "N / Página";
  - `EntityFormModal` (F1–F7): duas colunas, asterisco nos obrigatórios, ajuda (?), "Mais informações", erros 422 mapeados por campo e regras de negócio no topo;
  - `CatalogQuickModal` (F4).
- **Telas:**
  - **Cadastros:** empresas, clientes, fornecedores, unidades, funcionários (com certificações no ícone de pasta), cargo/função e setores (também por cadastro rápido no funcionário), equipamentos e grupos de recursos.
  - **Obras:** lista com o nível de acesso. O detalhe tem as abas Dados gerais, Endereço, Fases (árvore), Equipe, Histórico de status e Acesso (só nível administrar). "Mudar status" oferece só as transições permitidas.
- **Removidas:** as páginas-protótipo de clientes/fornecedores e obras (`PartiesPage`, `ProjectsPage`), substituídas pelas reais.
- **Testes:** frontend **28/28** (novos: `http.test.ts` — CSRF, 419, 401, query; `registry.test.ts` — menu por permissão, paginação local, formatação de CPF/CNPJ e máscara, transições e níveis). `tsc` e build ok; lint sem avisos novos além do `watch` do react-hook-form (o mesmo das telas de orçamento).
- **Teste ponta a ponta por HTTP real** (servidor temporário no `obraflow_testing`, tenant fictício "DEMO", nada no banco real):
  - login com senha errada → 401; login correto → 200;
  - criação de empresa, cargo, funcionário e obra → 201; mudança de status → 200;
  - sair → 204 e `/auth/me` → 401 em seguida;
  - escrita sem CSRF → 419; pré-verificação CORS libera só `http://localhost:5173`, com credenciais.

**Limitações conhecidas:**
- Selects carregam até 100 itens ativos (busca incremental fica para depois).
- O fornecedor principal do equipamento não é filtrado pela empresa no select; a API recusa com 422.
- Documentos (upload e vínculo) ainda não têm tela.

## Homologação (2026-11-17)

**Etapa das telas homologada pelo responsável** ("eu aprovo, e registre; a homologação é minha"), após o teste no navegador com o banco `obraflow`.
- Commit `d4fb5b5`, com CI run 43972095774 verde.
- As limitações conhecidas acima seguem como pendências.

## Primeiros passos (2026-11-17)

Página **"Primeiros passos"** (`/primeiros-passos`, primeiro item do menu), pedida pelo responsável:
- **Percurso:** Empresa → Cliente → Responsável técnico (opcional) → Obra → Orçamento → Planilha → Envio e aprovação.
- **Cada passo:** descrição, dica e atalho para a tela.
- **Situação de cada passo:** calculada a partir dos dados reais da organização, por consultas à API (nada é gravado). Valores possíveis: "Feito", "Pendente" ou "Sem acesso", conforme as permissões de quem vê. A planilha conta como feita quando há orçamento enviado ou aprovado, porque a API não aceita enviar orçamento vazio.
- **"Você está aqui":** marca o primeiro passo obrigatório pendente. Há também uma barra de progresso.
- **Testes:** `src/features/onboarding/steps.test.ts` (4). Frontend **29/29**.

## Máscaras de entrada (2026-11-17)

Pedido do responsável. O utilitário `src/utils/masks.ts` aplica a máscara progressiva enquanto o usuário digita e valida na tela, antes de enviar:

| Máscara | Formato | Onde | Validação na tela |
|---|---|---|---|
| CPF | 000.000.000-00 | Funcionários | Dígitos verificadores |
| CNPJ | 00.000.000/0000-00 | Empresas | Dígitos verificadores |
| CPF/CNPJ | Conforme o tipo (física/jurídica); trocar o tipo reaplica a máscara | Clientes, fornecedores | Dígitos verificadores do tipo escolhido |
| Telefone/celular | (00) 0000-0000 com 10 dígitos; (00) 00000-0000 com 11 | Empresas, clientes, fornecedores, funcionários; telefone formatado também nas listas | DDD + 8 ou 9 dígitos |
| CEP | 00000-000 | Endereço da obra | 8 dígitos |

- **Teclado:** campos com máscara abrem o teclado numérico (ou de telefone) em celulares.
- **Valor mascarado pela API:** o CPF que chega como `***.982.247-**` (D-P21) **não** é alterado nem reenviado.
- **Servidor:** a API continua sendo a autoridade. Grava documentos e CEP só com dígitos e confere os dígitos verificadores de novo.
- **Testes:** `src/utils/masks.test.ts` (6). Frontend **35/35**.
