# FASE 4D — RELATÓRIO DO ORÇAMENTO (PDF, XLSX E DOCX): PROPOSTA DE MODELO

> **Proposta de 2026-11-18, aguardando aprovação.** Pedido do responsável: relatório do orçamento da obra em PDF, XLSX e DOCX, com acabamento premium.
>
> **Já decidido pelo responsável (A-53):**
> - **quando:** agora, antes da 6B;
> - **motor do PDF:** Chrome headless;
> - **marca:** a do ObraFlow, sem logo por empresa;
> - **analítico:** as composições entram já na primeira versão.
>
> As decisões D-P77 a D-P86 abaixo ainda precisam de aprovação. Nada será implementado antes disso.

## 1. Objetivo

Hoje o orçamento só pode ser visto na tela. A empresa precisa **entregar o orçamento ao cliente** e **arquivá-lo** em três formatos:

- **PDF:** o documento final, com acabamento premium, para enviar e imprimir;
- **XLSX:** para conferir e trabalhar os números (uma aba por parte);
- **DOCX:** para editar textos antes de enviar (proposta comercial, observações).

Os três saem da **mesma versão do orçamento** e mostram os mesmos números. O relatório só lê dados: **não há tabela nova, nem migration, nem dado gravado no `obraflow`**.

Acesso: na tela do orçamento, botão **Relatório**. Ele abre uma janela para escolher o formato, as partes e as opções, e depois baixa o arquivo.

## 2. Conteúdo do relatório

| # | Parte | Conteúdo | Opcional? |
|---|---|---|---|
| 1 | **Capa** | Marca ObraFlow, título "Orçamento", código e versão (ex.: ORC-003/2026 · v2), situação (aprovado, em revisão, rascunho), obra (código, nome, endereço), cliente, empresa que orça (razão social e CNPJ), data-base e regime de encargos (base SINAPI MT 12/2024, desonerada), data de emissão | Não |
| 2 | **Resumo** | Custo direto, custos indiretos, BDI (%), desconto e **valor final**. Totais por etapa (com % do total) e por tipo de insumo (material, mão de obra, equipamento, serviço) | Não |
| 3 | **Orçamento sintético** | Etapas e itens: item (EAP), código, fonte (SINAPI, catálogo, próprio), descrição, unidade, quantidade, preço unitário sem BDI, preço unitário com BDI e total. Subtotal por etapa e total geral | Não |
| 4 | **Orçamento analítico** | Cada item com a sua composição: insumos e composições auxiliares com código, descrição, unidade, coeficiente, preço unitário e total. Item sem composição aparece como "preço direto" | Sim (marcado por padrão) |
| 5 | **BDI e encargos** | BDI global e os diferenciados por etapa ou item. Quando calculado pela fórmula do TCU, mostra a fórmula e cada parcela: administração central (AC), seguro (S), risco (R), garantia (G), despesas financeiras (DF), lucro (L) e tributos (I). Quando informado, só o percentual. Também os custos indiretos e o regime de encargos sociais | Sim |
| 6 | **Curva ABC** | Dos serviços e dos insumos: posição, descrição, valor, %, % acumulado e faixa (A, B ou C). No PDF, com gráfico de Pareto | Sim |
| 7 | **Fechamento** | Observações (texto livre digitado na hora, não é gravado), local e data, e campos de assinatura do responsável técnico (nome, CREA/CAU, ART/RRT) e do cliente | Sim |

**Identidade visual (premium):**
- cabeçalho com a marca ObraFlow e o código do orçamento; rodapé com "página X de Y", data de emissão e "Gerado pelo ObraFlow";
- fonte Geist (licença SIL OFL, embutida no arquivo, sem buscar nada na internet), cores e tokens da marca (`documentation/marca`);
- tabelas com linhas alternadas, etapas destacadas, números alinhados à direita no formato brasileiro (R$ 1.234,56) e cabeçalho da tabela repetido a cada página;
- **marca d'água "RASCUNHO"** em versões que não estão aprovadas;
- A4: capa, resumo e fechamento em retrato; sintético, analítico e curva ABC em **paisagem** (cabem mais colunas legíveis).

## 3. Arquitetura

```
GET /api/v1/budgets/{uuid}/versions/{n}/report?format=pdf|xlsx|docx&parts=...&notes=...
        │
        ▼
BudgetReportBuilder ── lê a versão (BudgetCalculator, BdiResolver, AbcCurve) e monta um ReportData único
        │
        ├── PdfReportRenderer  → Blade (HTML + CSS de impressão + SVG do gráfico) → Chrome headless (printToPDF)
        ├── XlsxReportRenderer → OpenSpout (abas: Resumo, Sintético, Analítico, BDI, Curva ABC)
        └── DocxReportRenderer → PHPWord (mesmas partes; gráfico como imagem PNG gerada pelo Chrome)
```

- Os números vêm **todos** do `ReportData`, montado com `Dec` (bcmath). Os renderizadores só formatam, então os três formatos não podem divergir.
- O gráfico da curva ABC é um **SVG gerado no servidor** (sem JavaScript). No DOCX vai como PNG (captura do mesmo SVG pelo Chrome).
- Geração **na hora** (síncrona), com limite de tempo. O arquivo não fica guardado no servidor.
- Nome do arquivo: `ORC-003-2026-v2-orcamento.pdf` (sem dados pessoais).

## 4. Decisões propostas (D-P77 a D-P86)

| ID | Decisão | Recomendação |
|---|---|---|
| **D-P77** | **Biblioteca para comandar o Chrome** | **chrome-php/chrome 1.16.1** (MIT, publicada em 06/07/2026). É PHP puro e fala direto com o Chrome, **sem Node** no servidor. Alternativa: spatie/browsershot (MIT), que exige Node e Puppeteer no servidor |
| **D-P78** | **Chrome no servidor** | Caminho do executável por configuração (`CHROME_PATH`). Local: o Chrome já instalado. Produção: Chrome ou Chromium instalado no servidor (ou container). Se não houver Chrome, o PDF responde com erro claro e o XLSX e o DOCX continuam funcionando |
| **D-P79** | **Biblioteca do DOCX** | **phpoffice/phpword 1.4.0** (publicada em 05/06/2025). A licença é **LGPL-3.0**: pode ser usada como dependência sem abrir o código do ObraFlow, desde que a biblioteca não seja modificada. Precisa do seu aceite. Alternativa sem LGPL: DOCX montado pelo próprio ObraFlow (mais trabalho, menos recursos) |
| **D-P80** | **Permissão** | Quem vê o orçamento (`budgets.view` + acesso à obra) pode gerar o relatório. **Sem permissão nova**, então nada muda no banco |
| **D-P81** | **Auditoria** | Registrar `budget.report_exported` (formato, partes e versão), porque os dados saem do sistema. Não grava o arquivo |
| **D-P82** | **Versões permitidas** | Qualquer versão pode ser exportada. As não aprovadas saem com a marca d'água "RASCUNHO" e a situação na capa |
| **D-P83** | **Valores mostrados** | Padrão: preço unitário sem BDI, com BDI e total com BDI. Opção na janela: "**ocultar o BDI**" (o cliente vê só o preço final) e "ocultar o analítico" |
| **D-P84** | **XLSX** | Uma aba por parte, com formatação (moedas, % e larguras) e **valores fixos**, sem fórmulas, para que o arquivo mostre exatamente o mesmo que o PDF. A curva ABC vai como tabela (o OpenSpout não desenha gráficos) |
| **D-P85** | **Observações e assinaturas** | Digitadas na janela do relatório, só para aquele arquivo (não são gravadas). Os dados do responsável técnico podem virar cadastro numa etapa futura |
| **D-P86** | **Limite de tamanho** | Até 3.000 itens por relatório (o analítico pode passar de 100 páginas). Acima disso, aviso para gerar sem o analítico. Tempo máximo de 60 s |

## 5. Etapas (após aprovação)

1. **Dados:** `BudgetReportBuilder` e `ReportData`, com testes conferindo totais contra a tela.
2. **XLSX** (OpenSpout, já instalado).
3. **PDF:** instalar chrome-php/chrome; layout Blade premium, fonte embutida, gráfico SVG, cabeçalho e rodapé, marca d'água, retrato e paisagem.
4. **DOCX:** instalar PHPWord (se a D-P79 for aprovada).
5. **Tela:** botão Relatório e janela com formato, partes e opções.
6. **Testes e CI:** conteúdo e totais dos três formatos, permissões e isolamento entre tenants. No CI, o teste do PDF usa o Chrome do runner. Por fim, servidores para você testar e **homologar a 4D**.

Nenhuma etapa grava no banco `obraflow` (sem migrations e sem permissões novas).

## 6. Riscos

- **Chrome em produção:** a hospedagem precisa permitir instalar o Chrome (ou Chromium). Hospedagem compartilhada costuma não permitir. Isso deve ser confirmado antes de publicar.
- **Orçamentos muito grandes:** o analítico pode gerar centenas de páginas. A D-P86 limita o tamanho e o tempo.
- **DOCX:** o Word não repete fielmente o layout do PDF (fontes e quebras de página variam). O DOCX é para edição; o documento final é o PDF.
- **Licença LGPL (PHPWord):** é aceitável como dependência, mas não pode ser modificada sem publicar a modificação.
