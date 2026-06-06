<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskTicket;
use Illuminate\Console\Command;

class MovideskDebugClienteCommand extends Command
{
    protected $signature   = 'movidesk:debug-cliente';
    protected $description = 'Compara clientes/CNPJ do Movidesk com customers do Time Conect';

    public function handle(): int
    {
        // Agrega por organização + CNPJ do Movidesk
        $rows = MovideskTicket::whereNotNull('solicitante')
            ->selectRaw("
                solicitante->>'organization' as org,
                solicitante->>'cpf_cnpj'     as cnpj_raw,
                COUNT(*)                     as tickets,
                SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) as vinculados
            ")
            ->groupByRaw("solicitante->>'organization', solicitante->>'cpf_cnpj'")
            ->orderByDesc('tickets')
            ->get();

        // Carrega customers do Time Conect indexados por CGC normalizado
        $customers = Customer::whereNotNull('cgc')->get()->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        $this->line('');
        $this->line('=== CLIENTES MOVIDESK x TIME CONECT (CNPJ) ===');
        $this->line('');

        $headers = [
            'Organização Movidesk',
            'CNPJ Movidesk (raw)',
            'CNPJ normalizado',
            'Tickets',
            'Vinculados',
            'Cliente no Time Conect?',
            'Nome no Time Conect',
        ];

        $tableData = $rows->map(function ($row) use ($customers) {
            $org      = $row->org  ?? '(vazio)';
            $cnpjRaw  = $row->cnpj_raw ?? '';
            $cnpjNorm = preg_replace('/[^0-9]/', '', $cnpjRaw);

            if ($cnpjNorm && isset($customers[$cnpjNorm])) {
                $found      = '✓ SIM (CNPJ)';
                $timeconectName = $customers[$cnpjNorm]->name ?? $customers[$cnpjNorm]->company_name ?? '—';
            } else {
                // Fallback: busca por nome
                $byName = Customer::where('name', $org)->orWhere('company_name', $org)->first();
                if ($byName) {
                    $found       = '~ SIM (nome)';
                    $timeconectName = $byName->name ?? $byName->company_name ?? '—';
                } else {
                    $found       = '✗ NÃO';
                    $timeconectName = '—';
                }
            }

            return [
                mb_substr($org, 0, 35),
                mb_substr($cnpjRaw, 0, 20),
                $cnpjNorm ?: '—',
                $row->tickets,
                $row->vinculados,
                $found,
                mb_substr($timeconectName, 0, 30),
            ];
        })->toArray();

        $this->table($headers, $tableData);

        $semOrg = MovideskTicket::whereNotNull('solicitante')
            ->whereRaw("(solicitante->>'organization') IS NULL OR (solicitante->>'organization') = ''")
            ->count();

        if ($semOrg > 0) {
            $this->line('');
            $this->warn("{$semOrg} ticket(s) com solicitante sem organização.");
        }

        $semSolicitante = MovideskTicket::whereNull('solicitante')->count();
        $this->line('');
        $this->line("{$semSolicitante} ticket(s) sem solicitante algum.");
        $this->line('');

        return self::SUCCESS;
    }
}
