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
 * Planilha do detalhamento do fechamento de um cliente (Relatório de Apontamentos).
 *
 * Agrupa por PROJETO, com subtotais de horas por projeto e uma linha final de
 * "Valor a pagar" (apenas o total — sem coluna de valor por linha).
 *
 * Colunas: Projeto, Data, Consultor, Ticket, Título, Horas, Descrição.
 * A Descrição (observação do apontamento) vai SOMENTE no Excel.
 *
 * Modelo VEDAMOTORS: quando $vedamotors=true, as colunas de ticket viram
 * "Ticket ERPSERV" (o ticket normal) e "Ticket Vedamotors" (extraído do título,
 * já preenchido em $r['titulo'] pelo controller via vedaTicket()).
 *
 * Recebe linhas já no formato achatado de apontamentos (arrays).
 */
class FechamentoClienteExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    /** @var array<int,array<string,mixed>> linhas do detalhamento (achatadas por projeto) */
    protected array $rows;

    protected string $clienteName;

    protected string $periodo;

    /** Valor a pagar do cliente (serviços + despesas, ou snapshot) */
    protected float $totalPagar;

    /** Modelo Vedamotors: renomeia colunas de ticket. */
    protected bool $vedamotors;

    /** Linhas (1-based) que receberão estilo de subtotal para o WithStyles */
    protected array $subtotalRows = [];

    protected int $totalRow = 0;

    public function __construct(array $rows, string $clienteName, string $periodo, float $totalPagar, bool $vedamotors = false)
    {
        $this->rows        = $rows;
        $this->clienteName = $clienteName;
        $this->periodo     = $periodo;
        $this->totalPagar  = $totalPagar;
        $this->vedamotors  = $vedamotors;
    }

    public function title(): string
    {
        return 'Apontamentos';
    }

    public function headings(): array
    {
        return [
            'Projeto',
            'Data',
            'Consultor',
            $this->vedamotors ? 'Ticket ERPSERV' : 'Ticket',
            $this->vedamotors ? 'Ticket Vedamotors' : 'Título',
            'Horas',
            'Descrição',
        ];
    }

    public function array(): array
    {
        $out = [];
        // O cabeçalho ocupa a linha 1 (WithHeadings). Os dados começam na linha 2.
        $line = 1;

        // Agrupa por projeto.
        $grouped = [];
        foreach ($this->rows as $r) {
            $key = $r['projeto'] ?? '—';
            $grouped[$key][] = $r;
        }

        if (empty($grouped)) {
            $line++;
            $out[] = ['Nenhum apontamento no período.', '', '', '', '', '', ''];
        }

        $totalHorasGeral = 0.0;

        foreach ($grouped as $projeto => $items) {
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
                    $r['projeto'] ?? '—',
                    isset($r['data']) ? \Carbon\Carbon::parse($r['data'])->format('d/m/Y') : '',
                    $r['consultor'] ?? '—',
                    $r['ticket'] ?? '',
                    $r['titulo'] ?? '',
                    $horas,
                    $descricao,
                ];
            }

            // Linha de subtotal do projeto
            $line++;
            $this->subtotalRows[] = $line;
            $out[] = [
                "Subtotal — {$projeto}", '', '', '', '',
                round($subtotalHoras, 2),
                '',
            ];
        }

        // Linha de "Valor a pagar" (total do fechamento; horas brutas somadas).
        $line++;
        $this->totalRow = $line;
        $out[] = [
            'VALOR A PAGAR', '', '', '', '',
            round($totalHorasGeral, 2),
            $this->totalPagar > 0 ? ('R$ ' . number_format($this->totalPagar, 2, ',', '.')) : '',
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30, // projeto
            'B' => 12, // data
            'C' => 24, // consultor
            'D' => 16, // ticket / ticket erpserv
            'E' => 22, // título / ticket vedamotors
            'F' => 10, // horas
            'G' => 60, // descrição
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'G';

        // Cabeçalho
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0E7490'],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Formato de horas (coluna F) — aplica em toda a coluna usada.
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("F2:F{$highestRow}")
            ->getNumberFormat()->setFormatCode('#,##0.00');

        // Quebra de texto na descrição
        $sheet->getStyle("G2:G{$highestRow}")->getAlignment()->setWrapText(true);

        // Subtotais
        foreach ($this->subtotalRows as $row) {
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '155E75']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'CFFAFE'],
                ],
            ]);
        }

        // Valor a pagar (total geral)
        if ($this->totalRow > 0) {
            $sheet->getStyle("A{$this->totalRow}:{$lastCol}{$this->totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0E7490'],
                ],
                'borders' => [
                    'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0E7490']],
                ],
            ]);
        }

        return [];
    }
}
