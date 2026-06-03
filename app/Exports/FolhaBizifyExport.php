<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Planilha da folha BIZIFY (réplica do template
 * "BIZIFY SOLUCOES TECNOLOGICAS LTDA.xls" usado na importação):
 *  - Cabeçalhos A1:N1 em negrito (J/M/N em vermelho = não importadas).
 *  - A=Cliente B=Operação C=Matrícula D=Nome E=Produção F=Variável G=Aj Custo
 *    H=Reemb I=Adto J=Total Créditos K=Descontos L=Adiantamento M=Total Débitos N=Líquido
 *  - J = SUM(E:I) | M = SUM(K,L) | N = J-M (fórmulas, em vermelho).
 *  - Rodapé: linha de totais (SUM por coluna) + 1 linha em branco + "Legenda" (vermelho) + frase.
 *  - Round-trip: re-importável (matrícula numérica; totais/legenda são pulados).
 *
 * Cada $row deve conter: matricula, nome, status (Operação), producao, variavel,
 * aj_custo, reemb, adto, descontos, adiantamento.
 */
class FolhaBizifyExport implements WithEvents, WithTitle
{
    private const CLIENTE = 'BIZIFY SOLUCOES TECNOLOGICAS LTDA';
    private const HEADERS = [
        'Cliente', 'Operação', 'Matricula', 'Nome', 'Producao', 'Variável', 'Aj Custo',
        'Reemb', 'Adto', 'Total Créditos', 'Descontos', 'Adiantamento', 'Total Débitos', 'Líquido',
    ];
    private const RED = 'FFFF0000';

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(protected array $rows)
    {
    }

    public function title(): string
    {
        return 'Sheet1';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // ── Cabeçalho ──
                foreach (self::HEADERS as $i => $h) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
                }
                $sheet->getStyle('A1:N1')->getFont()->setBold(true);
                foreach (['J', 'M', 'N'] as $c) {
                    $sheet->getStyle("{$c}1")->getFont()->getColor()->setARGB(self::RED);
                }

                // ── Dados ──
                $r = 2;
                foreach ($this->rows as $row) {
                    $sheet->setCellValueExplicit("A{$r}", self::CLIENTE, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("B{$r}", (string) ($row['status'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("C{$r}", (string) ($row['matricula'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("D{$r}", (string) ($row['nome'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValue("E{$r}", (float) ($row['producao'] ?? 0));
                    $sheet->setCellValue("F{$r}", (float) ($row['variavel'] ?? 0));
                    $sheet->setCellValue("G{$r}", (float) ($row['aj_custo'] ?? 0));
                    $sheet->setCellValue("H{$r}", (float) ($row['reemb'] ?? 0));
                    $sheet->setCellValue("I{$r}", (float) ($row['adto'] ?? 0));
                    $sheet->setCellValue("J{$r}", "=SUM(E{$r}:I{$r})");
                    $sheet->setCellValue("K{$r}", (float) ($row['descontos'] ?? 0));
                    $sheet->setCellValue("L{$r}", (float) ($row['adiantamento'] ?? 0));
                    $sheet->setCellValue("M{$r}", "=SUM(K{$r},L{$r})");
                    $sheet->setCellValue("N{$r}", "=J{$r}-M{$r}");
                    $r++;
                }
                $lastData = $r - 1;

                if ($lastData >= 2) {
                    foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                    foreach (['A', 'B', 'C', 'D'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getNumberFormat()->setFormatCode('@');
                    }
                    foreach (['J', 'M', 'N'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getFont()->getColor()->setARGB(self::RED);
                    }

                    // ── Linha de totais (SUM por coluna numérica) ──
                    $totRow = $lastData + 1;
                    foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $c) {
                        $sheet->setCellValue("{$c}{$totRow}", "=SUM({$c}2:{$c}{$lastData})");
                        $sheet->getStyle("{$c}{$totRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $sheet->getStyle("{$c}{$totRow}")->getFont()->setBold(true);
                    }
                    $legBase = $totRow;
                } else {
                    $legBase = $lastData;
                }

                // ── Legenda ──
                $legRow = $legBase + 2;
                $sheet->setCellValue("D{$legRow}", 'Legenda');
                $sheet->getStyle("D{$legRow}")->getFont()->getColor()->setARGB(self::RED);
                $sheet->setCellValue('D' . ($legRow + 1), 'As colunas identificadas em vermelho, não serão consideradas na importação');

                foreach (['A' => 34, 'B' => 16, 'C' => 12, 'D' => 34, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 10, 'I' => 10, 'J' => 14, 'K' => 12, 'L' => 14, 'M' => 14, 'N' => 12] as $col => $w) {
                    $sheet->getColumnDimension($col)->setWidth($w);
                }
            },
        ];
    }
}
