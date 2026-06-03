<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Planilha da lista de consultores do fechamento (com tipo de vínculo e tipo de contrato).
 * Recebe linhas já achatadas/filtradas pelo controller:
 *   ['consultor','email','tipo_vinculo','tipo_contrato','horas','total'].
 * (A coluna de Total (R$) foi removida — a lista mostra só horas.)
 */
class FechamentoConsultorListExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    /** @var array<int,array<string,mixed>> */
    protected array $rows;

    protected int $totalRow = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function title(): string
    {
        return 'Consultores';
    }

    public function headings(): array
    {
        return ['Consultor', 'E-mail', 'Tipo de Vínculo', 'Tipo de Contrato', 'Horas'];
    }

    public function array(): array
    {
        $out  = [];
        $line = 1; // cabeçalho ocupa a linha 1
        $totalHoras = 0.0;

        if (empty($this->rows)) {
            $out[] = ['Nenhum consultor no período/filtro.', '', '', '', ''];
        }

        foreach ($this->rows as $r) {
            $line++;
            $totalHoras += (float) ($r['horas'] ?? 0);
            $out[] = [
                $r['consultor'] ?? '—',
                $r['email'] ?? '',
                $r['tipo_vinculo'] ?? '—',
                $r['tipo_contrato'] ?? '—',
                round((float) ($r['horas'] ?? 0), 2),
            ];
        }

        $line++;
        $this->totalRow = $line;
        $out[] = ['TOTAL DE HORAS', '', '', '', round($totalHoras, 2)];

        return $out;
    }

    public function columnWidths(): array
    {
        return ['A' => 28, 'B' => 32, 'C' => 18, 'D' => 18, 'E' => 10];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'E';

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E7490']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("E2:E{$highestRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        if ($this->totalRow > 0) {
            $sheet->getStyle("A{$this->totalRow}:{$lastCol}{$this->totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E7490']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0E7490']]],
            ]);
        }

        return [];
    }
}
