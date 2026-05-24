<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Planilha de importação da folha (réplica EXATA do template
 * "MM_YYYY_M_ERPSERV CONSULTORIA DE SISTEMAS LTDA.xls"):
 *  - Sheet "Sheet1", cabeçalhos A1:P1 em negrito (K/N/O em vermelho).
 *  - Uma linha por consultor (A..P).
 *  - K = SUM(H,I,J)  | N = SUM(L,M)  | O = K-N  (fórmulas, em vermelho — não importadas).
 *  - Formatos: CPF/Matrícula/Status/Nome/Dias/Horista = texto (@); Valor Hora = #,##0.0000;
 *    demais numéricas = #,##0.00. Nome em teal (FF00AAAD).
 *  - Rodapé: 2 linhas em branco + "Legenda" (vermelho) + a frase da legenda.
 *
 * Cada $row deve conter: cpf, matricula, status, nome, dias, horas, valor_hora,
 * producao, variavel, reemb, descontos, adiantamento, horista_mensalista.
 */
class FolhaPagamentoExport implements WithEvents, WithTitle
{
    private const HEADERS = [
        'Cpf', 'Matricula', 'Status', 'Nome', 'Dias trabalhados', 'Horas Trabalhadas',
        'Valor Hora', 'Produção', 'Variável', 'Reemb', 'Total Rend', 'Descontos Diversos',
        'Adiantamento', 'Total Débitos', 'Líquido', 'Horista/Mensalista',
    ];

    private const RED  = 'FFFF0000';
    private const TEAL = 'FF00AAAD';

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
                $sheet->getStyle('A1:P1')->getFont()->setBold(true);
                foreach (['K', 'N', 'O'] as $c) {
                    $sheet->getStyle("{$c}1")->getFont()->getColor()->setARGB(self::RED);
                }

                // ── Dados ──
                $r = 2;
                foreach ($this->rows as $row) {
                    $sheet->setCellValueExplicit("A{$r}", (string) ($row['cpf'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("B{$r}", (string) ($row['matricula'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("C{$r}", (string) ($row['status'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("D{$r}", (string) ($row['nome'] ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("E{$r}", (string) ($row['dias'] ?? 0), DataType::TYPE_STRING);
                    $sheet->setCellValue("F{$r}", (float) ($row['horas'] ?? 0));
                    $sheet->setCellValue("G{$r}", (float) ($row['valor_hora'] ?? 0));
                    $sheet->setCellValue("H{$r}", (float) ($row['producao'] ?? 0));
                    $sheet->setCellValue("I{$r}", (float) ($row['variavel'] ?? 0));
                    $sheet->setCellValue("J{$r}", (float) ($row['reemb'] ?? 0));
                    $sheet->setCellValue("K{$r}", "=SUM(H{$r},I{$r},J{$r})");
                    $sheet->setCellValue("L{$r}", (float) ($row['descontos'] ?? 0));
                    $sheet->setCellValue("M{$r}", (float) ($row['adiantamento'] ?? 0));
                    $sheet->setCellValue("N{$r}", "=SUM(L{$r},M{$r})");
                    $sheet->setCellValue("O{$r}", "=K{$r}-N{$r}");
                    $sheet->setCellValueExplicit("P{$r}", (string) ($row['horista_mensalista'] ?? ''), DataType::TYPE_STRING);
                    $r++;
                }
                $lastData = $r - 1;

                if ($lastData >= 2) {
                    $sheet->getStyle("F2:F{$lastData}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("G2:G{$lastData}")->getNumberFormat()->setFormatCode('#,##0.0000');
                    foreach (['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                    foreach (['A', 'B', 'C', 'E', 'P'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getNumberFormat()->setFormatCode('@');
                    }
                    $sheet->getStyle("D2:D{$lastData}")->getFont()->getColor()->setARGB(self::TEAL);
                    foreach (['K', 'N', 'O'] as $c) {
                        $sheet->getStyle("{$c}2:{$c}{$lastData}")->getFont()->getColor()->setARGB(self::RED);
                    }
                }

                // ── Legenda (2 linhas em branco + cabeçalho vermelho + frase) ──
                $legRow = $lastData + 3;
                $sheet->setCellValue("D{$legRow}", 'Legenda');
                $sheet->getStyle("D{$legRow}")->getFont()->getColor()->setARGB(self::RED);
                $sheet->setCellValue('D' . ($legRow + 1), 'As colunas identificadas em vermelho, não serão consideradas na importação');

                // Larguras aproximadas (cosmético; não afeta import)
                foreach (['A' => 16, 'B' => 12, 'C' => 16, 'D' => 34, 'E' => 14, 'F' => 16, 'G' => 12, 'H' => 12, 'I' => 10, 'J' => 10, 'K' => 12, 'L' => 18, 'M' => 14, 'N' => 14, 'O' => 12, 'P' => 18] as $col => $w) {
                    $sheet->getColumnDimension($col)->setWidth($w);
                }
            },
        ];
    }
}
