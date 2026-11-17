# CALENDÁRIO E HORÁRIOS DA OBRA: MODELO APROVADO

> **Aprovado em 2026-11-17** (D-P57 a D-P64 conforme a recomendação; executar antes da parte 5A, A-45). Pedido do responsável em 2026-11-17: "o calendário me permite listar as exceções dos dias que não serão trabalhados na obra", com a biblioteca **FullCalendar**, já usada no projeto Veloterap.
> **Referência:** Brickup, obra → aba **Calendário** (vista em vídeo; os prints não ficam no repositório porque mostram dados de terceiros):
> - lista de exceções (Descrição, Data, Tipo, Criado por) ao lado do calendário (Mensal, Semanal, Diário);
> - botões **"Importar sábados e domingos como exceções"** e **"Nova exceção"**;
> - exceção **Única** ou **Recorrente** ("repetir a cada N dia/semana/quinzena/mês/ano");
> - aba **Horários**: grade de domingo a sábado com **Entrada 1, Saída 1, Entrada 2, Saída 2** (ex.: 07:00–12:00 e 13:00–16:00; sexta até 17:00; fim de semana em branco). Pedido do responsável: "Horário de Trabalho da Empresa".

## 1. Objetivo

Registrar, por obra, os dias em que **não há trabalho**: feriados, fins de semana, paradas e chuva prevista. Isso permite:
- ver no calendário da obra quais dias são de trabalho;
- usar esses dias, mais adiante, no cronograma (dias úteis, término previsto — Fase 6) e na contagem de "dias decorridos".

Nesta etapa: cadastro, lista, calendário e importação de sábados e domingos. O uso no cronograma fica para a Fase 6.

## 2. Tabelas propostas (2)

| Tabela | Colunas principais | Regras |
|---|---|---|
| `project_calendar_exceptions` | `uuid`, `project_id`, `description`, `date` (primeira ocorrência), `kind` (`single`, `recurring`), `repeat_every` (N ≥ 1), `repeat_unit` (`day`, `week`, `fortnight`, `month`, `year`), `repeat_until` (opcional), `created_by`, datas | obra do tenant (FK composta); `recurring` exige N e unidade, `single` não aceita; `repeat_until` ≥ `date` (CHECKs) |

| `work_schedules` — horário de trabalho | **dono:** `company_id` (padrão da empresa) **ou** `project_id` (horário da obra), exatamente um (CHECK); `weekday` (0 = domingo … 6 = sábado), `start_1`, `end_1`, `start_2`, `end_2` (hora, opcionais) | uma linha por dia da semana e dono (UNIQUE); par entrada/saída completo ou vazio; saída depois da entrada e 2º período depois do 1º (CHECKs). Dia sem horário = dia não trabalhado |

A regra de repetição é **gravada uma vez**, e as ocorrências são **calculadas** para o período pedido (o mês ou a semana em tela). Não se grava uma linha por dia: um fim de semana recorrente por 2 anos seria 200 linhas.

## 3. Decisões para aprovação

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P57** | Exceções **por obra** ou **da organização** (valendo para todas as obras)? | **Por obra**, como no Brickup: cada obra tem seu regime (algumas trabalham sábado). Um calendário-padrão da organização pode vir depois |
| **D-P58** | Repetição | **Única** ou **Recorrente**, repetindo a cada N **dias, semanas, quinzenas, meses ou anos**, com fim opcional. Sem fim, vale até o término previsto da obra; sem término previsto, por tempo indeterminado |
| **D-P59** | "Importar sábados e domingos" | Cria **duas exceções recorrentes semanais** ("Sábado" e "Domingo") a partir do início da obra, e não uma linha por dia. Se já existirem, não duplica |
| **D-P60** | Feriados nacionais | **Botão "Importar feriados nacionais do ano"**, calculado no próprio sistema (datas fixas e as que dependem da Páscoa: Carnaval, Sexta-feira Santa, Corpus Christi), sem serviço externo. Feriados estaduais e municipais são lançados à mão |
| **D-P61** | Biblioteca | **FullCalendar 7.0.2** (`@fullcalendar/react`, licença MIT), a mesma versão do Veloterap, com as visões **Mensal, Semanal e Diário**, em português, com as cores do ObraFlow (azul-safira), sem o tema roxo do Veloterap. Clicar num dia abre "Nova exceção" com a data preenchida; clicar numa exceção abre a edição |
| **D-P63** | **Horário de trabalho**: da empresa ou da obra? | **Os dois:** a empresa tem um **horário-padrão** (Cadastros → Empresas → aba Horários), e a obra **herda** esse padrão até alguém ajustá-lo na aba Horários da obra. O botão "Voltar ao padrão da empresa" restaura a herança. Até **2 períodos por dia** (manhã e tarde), como no Brickup. A tela mostra as horas por dia e por semana (ex.: seg–qui 8 h, sex 9 h = 41 h) |
| **D-P64** | Dia sem horário × exceção | Dia da semana **sem horário** já aparece como **não trabalhado** no calendário, sem precisar de exceção. As exceções servem para **datas** (feriados, paradas). O "Importar sábados e domingos" (D-P59) continua disponível para obras que não usam horário |
| **D-P62** | Permissões | **Sem permissão nova:** ver segue o acesso à obra (`projects.view` + nível `view`); criar, editar e excluir exige nível `edit` na obra (D-P24). Exceção pode ser excluída (é configuração, não histórico), com auditoria. Horário da empresa: `companies.view` / `companies.manage` |

## 4. Telas

- **Aba Horários** (na obra e na empresa): grade com os dias como colunas e Entrada 1, Saída 1, Entrada 2 e Saída 2 como linhas; total de horas por dia e por semana; botões "Salvar" e, na obra, "Voltar ao padrão da empresa".

Na obra, a nova aba **Calendário** tem duas colunas. Em telas estreitas, a lista fica acima do calendário.
- **Esquerda: lista de exceções** (Descrição, Data, Tipo, Repetição, Criado por), com busca e as ações editar e excluir.
- **Direita: o calendário.** Mensal, Semanal e Diário, com os botões "Hoje" e anterior/próximo. Os dias de exceção ficam destacados com a descrição.
- **No topo:** "Importar sábados e domingos", "Importar feriados nacionais" e "Nova exceção".
- **Modal da exceção:** descrição, data, tipo (Única ou Recorrente); se recorrente, "Repetir a cada [N] [dia/semana/quinzena/mês/ano]" e "Até" (opcional).

## 5. Etapas (após aprovação)

1. Tabelas, regra de repetição (cálculo das ocorrências), horários com herança, API e importações, com testes.
2. Abas Calendário (FullCalendar, lista e modal) e Horários (obra e empresa).
3. Testes, CI, migration no `obraflow` (com autorização) e homologação.

## 6. Riscos

- **Quinzena:** "a cada quinzena" = a cada 14 dias a partir da data inicial. Se a empresa usar "dia 1 e dia 15 do mês", a regra é outra (duas exceções mensais).
- **Fim de mês:** exceção mensal no dia 31 cai no último dia dos meses mais curtos.
- **Cronograma:** nesta etapa as exceções só aparecem no calendário. Elas passam a afetar prazos quando o cronograma existir (Fase 6).

## 7. Andamento

### Etapa 1 — backend (concluída)

- **Migrations:** `2026_11_17_350001` (`project_calendar_exceptions`) e `350002` (`work_schedules`).
  - Os CHECKs exigem a regra completa da exceção recorrente. Um teste mostrou que, no MySQL, comparar um valor vazio (NULL) não viola o CHECK; por isso o `IS NOT NULL` é explícito.
  - Horário: um único dono (empresa ou obra), dia da semana de 0 a 6, entrada e saída em pares completos e na ordem certa.
- **API** em `/projects/{uuid}`:
  - `calendar?from&to`: ocorrências e dias da semana sem horário;
  - `calendar/exceptions`: CRUD;
  - `calendar/import-weekends` e `calendar/import-holidays`;
  - `work-schedule`: ver, salvar e voltar ao padrão.
- **Horário-padrão da empresa:** `/companies/{uuid}/work-schedule`.
- **Feriados nacionais:**
  - datas fixas, mais o Dia da Consciência Negra a partir de 2024 (Lei 14.759/2023);
  - datas móveis calculadas a partir da Páscoa (algoritmo de Meeus): Carnaval e Corpus Christi entram como "ponto facultativo", Sexta-feira Santa como feriado.
- **Auditoria:**
  - CRUD das exceções em `registry.*`;
  - `calendar.imported`, `calendar.schedule_updated` e `calendar.schedule_reset`.
- **Testes:** `Feature/Teams/ProjectCalendarTest` (7). Suíte 433/433.

### Etapa 2 — telas (concluída)

- **Obra, aba Calendário:** lista de exceções ao lado do calendário.
  - FullCalendar 7.0.2, tema "classic" ligado aos tokens do ObraFlow (`calendar/fullcalendar.css`, sem cores fixas).
  - Visões Mensal, Semanal e Diária, em português.
  - Dias com exceção ficam em destaque; dias da semana sem horário ficam acinzentados.
  - Clicar num dia abre "Nova exceção" com a data; clicar numa exceção abre a edição.
  - Botões "Importar sábados e domingos", "Importar feriados nacionais do ano" (o ano visível no calendário) e "Nova exceção".
- **Obra, aba Horários:** grade com os dias como colunas e Entrada/Saída 1 e 2 como linhas, horas por dia e total da semana. A tela indica se o horário é próprio, herdado da empresa ou inexistente, e tem o botão "Voltar ao padrão da empresa".
- **Cadastros → Empresas:** ação "Horário de trabalho" em cada linha (horário-padrão, `companies.manage` para editar).
- **Testes frontend:** 39/39 (+2: texto da repetição, horas do dia).

**Aplicado no `obraflow` em 2026-11-17, com autorização do usuário:** migrations `350001` e `350002` (70 → 72). As tabelas foram criadas vazias, sem permissões novas.
