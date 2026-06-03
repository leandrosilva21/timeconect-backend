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
 * Planilha do detalhamento do fechamento de um consultor.
 *
 * Colunas: cliente, projeto, ticket, data, descrição, horas.
 * (A coluna de Valor foi removida — o relatório do consultor mostra só horas.)
 * Inclui subtotais por grupo (tipo de contrato) e uma linha de TOTAL DE HORAS.
 *
 * Recebe linhas já no formato de apontamentos() do controller (arrays).
 */
class FechamentoConsultorExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    /** @var array<int,array<string,mixed>> linhas do detalhamento (formato apontamentos) */
    protected array $rows;

    protected float $effectiveRate;

    protected string $consultantName;

    protected string $periodo;

    /** Total a pagar do consultor (vindo do index, inclui extras/banco) */
    protected float $totalPagar;

    /** Linhas (1-based) que receberão estilo de subtotal/total para o WithStyles */
    protected array $subtotalRows = [];

    protected int $totalRow = 0;

    public function __construct(array $rows, float $effectiveRate, string $consultantName, string $periodo, float $totalPagar)
    {
        $this->rows           = $rows;
        $this->effectiveRate  = $effectiveRate;
        $this->consultantName = $consultantName;
        $this->periodo        = $periodo;
        $this->totalPagar     = $totalPagar;
    }

    public function title(): string
    {
        return 'Fechamento';
    }

    public function headings(): array
    {
        return ['Cliente', 'Projeto', 'Ticket', 'Data', 'Descrição', 'Horas'];
    }

    public function array(): array
    {
        $out = [];
        // O cabeçalho ocupa a linha 1 (WithHeadings). Os dados começam na linha 2.
        $line = 1;

        // Agrupa por tipo de contrato, igual ao relatório da tela.
        $grouped = [];
        foreach ($this->rows as $r) {
            $key = $r['tipo_contrato_nome'] ?? 'Outros';
            $grouped[$key][] = $r;
        }

        if (empty($grouped)) {
            $line++;
            $out[] = ['Nenhum apontamento no período.', '', '', '', '', ''];
        }

        $totalHorasGeral = 0.0;

        foreach ($grouped as $tipo => $items) {
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
                    $r['cliente'] ?? '—',
                    $r['projeto'] ?? '—',
                    $r['ticket'] ?? '',
                    isset($r['data']) ? \Carbon\Carbon::parse($r['data'])->format('d/m/Y') : '',
                    $descricao,
                    $horas,
                ];
            }

            // Linha de subtotal do grupo
            $line++;
            $this->subtotalRows[] = $line;
            $out[] = [
                "Subtotal — {$tipo}", '', '', '', '',
                round($subtotalHoras, 2),
            ];
        }

        // Linha de TOTAL DE HORAS (soma das horas dos apontamentos exibidos).
        $line++;
        $this->totalRow = $line;
        $out[] = [
            'TOTAL DE HORAS', '', '', '', '',
            round($totalHorasGeral, 2),
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 28, // cliente
            'B' => 30, // projeto
            'C' => 14, // ticket
            'D' => 12, // data
            'E' => 60, // descrição
            'F' => 10, // horas
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'F';

        // Cabeçalho
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '7C3AED'],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Formato de horas (coluna F) — aplica em toda a coluna usada.
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("F2:F{$highestRow}")
            ->getNumberFormat()->setFormatCode('#,##0.00');

        // Quebra de texto na descrição
        $sheet->getStyle("E2:E{$highestRow}")->getAlignment()->setWrapText(true);

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
