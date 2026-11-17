<?php

namespace Tests\Support;

/**
 * Recorte com o MESMO layout dos arquivos SINAPI (cabeçalhos e linhas copiados da base MT 12/2024 desonerada;
 * dados públicos), gerado em XLSX na hora do teste.
 */
final class SinapiFixture
{
    public static function insumos(string $mes = '12/2024', string $encargos = 'DESONERADOS', array $extra = []): string
    {
        return XlsxFixture::write([
            ['A' => 'PRECOS DE INSUMOS - BANCO NACIONAL'],
            ['A' => "MES DE COLETA: {$mes}"],
            ['A' => 'LOCALIDADE: 4160 - CUIABA'],
            ['A' => "ENCARGOS SOCIAIS {$encargos} (%) HORISTA 79,65 MENSALISTA 43,45"],
            ['A' => 'CODIGO', 'B' => 'DESCRICAO DO INSUMO', 'C' => 'UNIDADE DE MEDIDA', 'D' => 'ORIGEM DO PRECO', 'E' => 'PRECO MEDIANO R$'],
            ['A' => '11270', 'B' => 'ABRACADEIRA DE LATAO PARA FIXACAO DE CABO PARA-RAIO, DIMENSOES 32 X 24 X 24 MM', 'C' => 'UN', 'D' => 'CR', 'E' => '2,66'],
            ['A' => '20078', 'B' => 'PASTA LUBRIFICANTE PARA TUBOS E CONEXOES COM JUNTA ELASTICA, EMBALAGEM DE *400* GR', 'C' => 'UN', 'D' => 'CR', 'E' => '31,75'],
            ['A' => '4750', 'B' => 'PEDREIRO (HORISTA)', 'C' => 'H', 'D' => 'CR', 'E' => '1.234,56'],
            ...$extra,
        ]);
    }

    public const HEADER = [
        'A' => 'DESCRICAO DA CLASSE', 'B' => 'SIGLA DA CLASSE', 'C' => 'DESCRICAO DO TIPO 1', 'D' => 'SIGLA DO TIPO 1', 'E' => 'CODIGO DO AGRUPADOR',
        'F' => 'DESCRICAO DO AGRUPADOR', 'G' => 'CODIGO DA COMPOSICAO', 'H' => 'DESCRICAO DA COMPOSICAO', 'I' => 'UNIDADE', 'J' => 'ORIGEM DE PREÇO',
        'K' => 'CUSTO TOTAL', 'L' => 'TIPO ITEM', 'M' => 'CODIGO ITEM', 'N' => 'DESCRIÇÃO ITEM', 'O' => 'UNIDADE ITEM', 'P' => 'ORIGEM DE PREÇO ITEM',
        'Q' => 'COEFICIENTE', 'R' => 'PRECO UNITARIO', 'S' => 'CUSTO TOTAL', 'T' => 'CUSTO MAO DE OBRA', 'U' => '% MAO DE OBRA', 'V' => 'CUSTO MATERIAL',
        'W' => '% MATERIAL', 'X' => 'CUSTO EQUIPAMENTO', 'Y' => '% EQUIPAMENTO', 'Z' => 'CUSTO SERVICOS TERCEIROS', 'AA' => '% SERVICOS TERCEIROS',
        'AB' => 'CUSTO OUTROS', 'AC' => '% OUTROS', 'AD' => 'VINCULO',
    ];

    public static function analitico(bool $comFilha = true, string $encargos = 'DESONERADOS'): string
    {
        $comp = fn (string $code, string $desc, string $unit, string $total) => ['A' => 'ASSENTAMENTO DE TUBOS E PECAS', 'B' => 'ASTU', 'C' => 'FORNEC E/OU ASSENT DE TUBO', 'D' => '45',
            'G' => $code, 'H' => $desc, 'I' => $unit, 'J' => 'ATRIBUÍDO SÃO PAULO', 'K' => $total];
        $rows = [
            ['A' => 'PCI.818.01 - CUSTOS DE COMPOSIÇÕES ANALÍTICO DATA DE EMISSÃO:14/01/2025'],
            ['A' => "ENCARGOS SOCIAIS {$encargos}: 79,65%(HORA) 43,45%(MÊS)"],
            ['A' => 'ABRANGENCIA: NACIONAL LOCALIDADE : CUIABA DATA DE PREÇO : 12/2024'],
            self::HEADER,
            // composição-pai: resumo de custos + itens (a filha 88316 só aparece DEPOIS no arquivo)
            $comp('97141', 'ASSENTAMENTO DE TUBO DE FERRO FUNDIDO PARA REDE DE ÁGUA, DN 80 MM', 'M', '3,84') + ['T' => '1,49', 'V' => '0,93', 'X' => '1,42', 'Z' => '0,00', 'AB' => '0,00'],
            $comp('97141', '…', 'M', '3,84') + ['L' => 'INSUMO', 'M' => '20078', 'N' => 'PASTA LUBRIFICANTE', 'O' => 'UN', 'P' => 'COEFICIENTE DE REPRESENTATIVIDADE', 'Q' => '0,0046000', 'R' => '31,75', 'S' => '0,14'],
            $comp('97141', '…', 'M', '3,84') + ['L' => 'INSUMO', 'M' => '99999', 'N' => 'INSUMO SÓ CITADO NA COMPOSIÇÃO', 'O' => 'KG', 'P' => 'COLETADO', 'Q' => '1,5000000', 'R' => '2,00', 'S' => '3,00'],
        ];
        if ($comFilha) {
            $rows[] = $comp('97141', '…', 'M', '3,84') + ['L' => 'COMPOSICAO', 'M' => '88316', 'N' => 'SERVENTE COM ENCARGOS COMPLEMENTARES', 'O' => 'H', 'P' => 'COLETADO', 'Q' => '0,0320000', 'R' => '18,34', 'S' => '0,58'];
        } else {
            $rows[] = $comp('97141', '…', 'M', '3,84') + ['L' => 'COMPOSICAO', 'M' => '77777', 'N' => 'FILHA AUSENTE', 'O' => 'H', 'Q' => '1,0000000'];
        }
        $rows[] = $comp('88316', 'SERVENTE COM ENCARGOS COMPLEMENTARES', 'H', '18,34') + ['T' => '18,34'];
        $rows[] = $comp('88316', '…', 'H', '18,34') + ['L' => 'INSUMO', 'M' => '4750', 'N' => 'PEDREIRO (HORISTA)', 'O' => 'H', 'P' => 'COLETADO', 'Q' => '1,0000000', 'R' => '18,34', 'S' => '18,34'];

        return XlsxFixture::write($rows);
    }

    public static function familias(): string
    {
        return XlsxFixture::write([
            ['A' => 'CAIXA ECONÔMICA FEDERAL SINAPI - Relatório de Família de Insumos'],
            ['A' => 'Cód. Insumo Representativo', 'B' => 'Cód. Insumo Representado', 'C' => 'Descrição do Insumo', 'D' => 'Unidade de Cálculo', 'E' => 'Coeficiente', 'F' => 'Categoria', 'G' => 'Macro-Classe', 'H' => 'Vínculo'],
            ['A' => '11270', 'B' => '11270', 'C' => 'ABRACADEIRA', 'D' => 'UN', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'MATERIAL', 'H' => 'NACIONAL CAIXA'],
            ['A' => '4750', 'B' => '4750', 'C' => 'PEDREIRO', 'D' => 'H', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'MÃO DE OBRA', 'H' => 'NACIONAL CAIXA'],
            ['A' => '99999', 'B' => '99999', 'C' => 'X', 'D' => 'KG', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'EQUIPAMENTO (LOCAÇÃO)', 'H' => 'NACIONAL CAIXA'],
            ['A' => '20078', 'B' => '20078', 'C' => 'PASTA', 'D' => 'UN', 'E' => '1', 'F' => 'REPRESENTATIVO', 'G' => 'ESPECIAIS', 'H' => 'NACIONAL CAIXA'],
        ]);
    }
}
