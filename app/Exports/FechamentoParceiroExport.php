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
 * Planilha do detalhamento do fechamento de um parceiro.
 *
 * Colunas: Consultor, Data, Cliente, Projeto, Ticket, Título, Solicitante, Horas, Descrição.
 * A Descrição (observação do apontamento) vai SOMENTE no Excel.
 * Inclui subtotais por consultor e uma linha de TOTAL GERAL de horas.
 *
 * Recebe linhas já no formato de apontamentos() do controller (arrays).
 */
class FechamentoParceiroExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    /** @var array<int,array<string,mixed>> linhas do detalhamento (formato apontamentos) */
    protected array $rows;

    protected string $parceiroName;

    protected string $periodo;

    /** Total a pagar do parceiro (serviços + despesas, ou snapshot) */
    protected float $totalPagar;

    /** Linhas (1-based) que receberão estilo de subtotal para o WithStyles */
    protected array $subtotalRows = [];

    protected int $totalRow = 0;

    public function __construct(array $rows, string $parceiroName, string $periodo, float $totalPagar)
    {
        $this->rows         = $rows;
        $this->parceiroName = $parceiroName;
        $this->periodo      = $periodo;
        $this->totalPagar   = $totalPagar;
    }

    public function title(): string
    {
        return 'Fechamento';
    }

    public function headings(): array
    {
        return ['Consultor', 'Data', 'Cliente', 'Projeto', 'Ticket', 'Título', 'Solicitante', 'Horas', 'Descrição'];
    }

    public function array(): array
    {
        $out = [];
        // O cabeçalho ocupa a linha 1 (WithHeadings). Os dados começam na linha 2.
        $line = 1;

        // Agrupa por consultor.
        $grouped = [];
        foreach ($this->rows as $r) {
            $key = $r['consultor'] ?? '—';
            $grouped[$key][] = $r;
        }

        if (empty($grouped)) {
            $line++;
            $out[] = ['Nenhum apontamento no período.', '', '', '', '', '', '', '', ''];
        }

        $totalHorasGeral = 0.0;

        foreach ($grouped as $consultor => $items) {
            $subtotalHoras = 0.0;

            foreach ($items as $r) {
                $line++;
                $horas = (float) ($r['horas'] ?? 0);
                $subtotalHoras += $horas;
                $totalHorasGeral += $horas;

                $descricao = $r['observacao']
                    ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $r['observacao'])))
                    : '';

                $out[] = [
                    $r['consultor'] ?? '—',
                    isset($r['data']) ? \Carbon\Carbon::parse($r['data'])->format('d/m/Y') : '',
                    $r['cliente'] ?? '—',
                    $r['projeto'] ?? '—',
                    $r['ticket'] ?? '',
                    $r['titulo'] ?? '',
                    $r['solicitante'] ?? '',
                    $horas,
                    $descricao,
                ];
            }

            // Linha de subtotal do consultor
            $line++;
            $this->subtotalRows[] = $line;
            $out[] = [
                "Subtotal — {$consultor}", '', '', '', '', '', '',
                round($subtotalHoras, 2),
                '',
            ];
        }

        // Linha de TOTAL GERAL (total a pagar do fechamento; horas brutas somadas).
        $line++;
        $this->totalRow = $line;
        $out[] = [
            'TOTAL A PAGAR', '', '', '', '', '', '',
            round($totalHorasGeral, 2),
            $this->totalPagar > 0 ? ('R$ ' . number_format($this->totalPagar, 2, ',', '.')) : '',
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24, // consultor
            'B' => 12, // data
            'C' => 26, // cliente
            'D' => 28, // projeto
            'E' => 14, // ticket
            'F' => 34, // título
            'G' => 24, // solicitante
            'H' => 10, // horas
            'I' => 60, // descrição
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'I';

        // Cabeçalho
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '7C3AED'],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Formato de horas (coluna H) — aplica em toda a coluna usada.
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("H2:H{$highestRow}")
            ->getNumberFormat()->setFormatCode('#,##0.00');

        // Quebra de texto na descrição
        $sheet->getStyle("I2:I{$highestRow}")->getAlignment()->setWrapText(true);

        // Subtotais
        foreach ($this->subtotalRows as $row) {
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '5B21B6']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'EDE9FE'],
                ],
            ]);
        }

        // Total geral
        if ($this->totalRow > 0) {
            $sheet->getStyle("A{$this->totalRow}:{$lastCol}{$this->totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '7C3AED'],
                ],
                'borders' => [
                    'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '7C3AED']],
                ],
            ]);
        }

        return [];
    }
}
