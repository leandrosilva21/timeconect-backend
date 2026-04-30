<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use Illuminate\Console\Command;

class MovideskLinkOrgCommand extends Command
{
    protected $signature   = 'movidesk:link-org {org_name? : Nome da organização no Movidesk} {customer_id? : ID do cliente no sistema}';
    protected $description = 'Vincula manualmente uma organização Movidesk a um cliente; sem argumentos lista todas as orgs';

    public function handle(): int
    {
        $orgName    = $this->argument('org_name');
        $customerId = $this->argument('customer_id');

        // Modo listagem
        if (!$orgName) {
            $orgs = MovideskOrganization::orderBy('name')->get();

            $this->table(
                ['ID', 'Nome (Movidesk)', 'CNPJ', 'customer_id', 'Cliente vinculado'],
                $orgs->map(function ($o) {
                    $customerName = $o->customer_id
                        ? (Customer::find($o->customer_id)?->name ?? "#{$o->customer_id}")
                        : '(sem vínculo)';
                    return [
                        $o->id,
                        mb_substr($o->name, 0, 40),
                        $o->cnpj ?: '—',
                        $o->customer_id ?: '—',
                        $customerName,
                    ];
                })->toArray()
            );

            return self::SUCCESS;
        }

        // Modo vínculo
        if (!$customerId) {
            $this->error('Informe o customer_id. Exemplo: php artisan movidesk:link-org "HUB ACCOUNTING" 42');
            return self::FAILURE;
        }

        $org = MovideskOrganization::where('name', $orgName)->first();
        if (!$org) {
            // tenta case-insensitive
            $org = MovideskOrganization::whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($orgName))])->first();
        }

        if (!$org) {
            $this->error("Organização não encontrada: {$orgName}");
            $this->line('Execute sem argumentos para listar todas as orgs.');
            return self::FAILURE;
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            $this->error("Cliente #{$customerId} não encontrado.");
            return self::FAILURE;
        }

        $org->customer_id = (int) $customerId;
        $org->save();

        $this->info("Vínculo salvo: [{$org->name}] → [{$customer->name}] (customer_id={$customerId})");
        $this->line('Execute movidesk:sync-orgs para reprocessar os tickets.');

        return self::SUCCESS;
    }
}
