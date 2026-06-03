<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Planilha das DESPESAS encaminhadas ao fechamento de um consultor.
 * Pagas (avulso) aparecem com a data do pagamento e NÃO entram no saldo;
 * a última linha traz o "Saldo a pagar no fechamento" (soma das não-pagas).
 *
 * Recebe as despesas no formato de despesasConsultor() (arrays).
 */
class FechamentoConsultorDespesaExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        protected array $despesas,
        protected string $consultantName,
        protected string $periodo,
    ) {}

    public function title(): string
    {
        return 'Despesas';
    }

    public function headings(): array
    {
        return ['Data', 'Descrição', 'Categoria', 'Cliente', 'Projeto', 'Pagamento', 'Valor (R$)'];
    }

    public function array(): array
    {
        $rows  = [];
        $saldo = 0.0;

        foreach ($this->despesas as $d) {
            $pago = $d['is_paid']
                ? ($d['paid_at'] ? 'Pago ' . Carbon::parse($d['paid_at'])->format('d/m/Y') : 'Pago')
                : 'No fechamento';

            if (empty($d['is_paid'])) {
                $saldo += (float) $d['valor'];
            }

            $rows[] = [
                Carbon::parse($d['data'])->format('d/m/Y'),
                $d['descricao'] ?: '—',
                $d['categoria'] ?? '—',
                $d['cliente'] ?? '—',
                $d['projeto'] ?? '—',
                $pago,
                number_format((float) $d['valor'], 2, ',', '.'),
            ];
        }

        $rows[] = ['', '', '', '', '', 'Saldo a pagar no fechamento', number_format($saldo, 2, ',', '.')];

        return $rows;
    }
}
