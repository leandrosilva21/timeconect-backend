<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskAgent;
use App\Models\MovideskOrganization;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\MovideskService;
use Illuminate\Console\Command;

class MovideskSyncOrgsCommand extends Command
{
    protected $signature   = 'movidesk:sync-orgs';
    protected $description = 'Busca organizações do Movidesk via /persons e atualiza cpf_cnpj + customer_id nos tickets e apontamentos';

    public function handle(MovideskService $service): int
    {
        $this->info('Buscando organizações no Movidesk...');
        $orgs = $service->fetchOrganizations();
        $this->info(count($orgs) . ' organizações encontradas.');

        if (empty($orgs)) {
            $this->warn('Nenhuma organização retornada. Verifique o token e o endpoint /persons.');
            return self::FAILURE;
        }

        $this->table(
            ['Organização', 'CNPJ'],
            collect($orgs)->map(fn($o) => [mb_substr($o['name'], 0, 40), $o['cpfCnpj'] ?: '(vazio)'])->values()->toArray()
        );

        // ── 1. Salva/atualiza tabela movidesk_organizations ───────────────────
        $this->info('Sincronizando tabela de organizações...');
        $customersByCnpj = Customer::whereNotNull('cgc')
            ->get()
            ->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        foreach ($orgs as $org) {
            $cnpj       = $org['cpfCnpj'] ?? null;
            $customerId = null;

            if ($cnpj) {
                $cnpjNorm   = preg_replace('/[^0-9]/', '', $cnpj);
                $customerId = $customersByCnpj[$cnpjNorm]->id ?? null;

                if (!$customerId) {
                    $customerId = Customer::where('name', $org['name'])
                        ->orWhere('company_name', $org['name'])
                        ->value('id');
                }
            }

            MovideskOrganization::updateOrCreate(
                ['movidesk_id' => (string) $org['id']],
                [
                    'name'        => $org['name'],
                    'cnpj'        => $cnpj ?: null,
                    'is_active'   => $org['isActive'] ?? true,
                    'customer_id' => $customerId,
                ]
            );
        }

        // ── 2. Atualiza cpf_cnpj e customer_id nos movidesk_tickets ──────────
        $this->info('Atualizando tickets...');
        $updatedTickets = 0;

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(function (MovideskTicket $ticket) use ($orgs, &$updatedTickets) {
            $orgName = trim($ticket->solicitante['organization'] ?? '');
            if (!$orgName) return;

            $key  = strtolower($orgName);
            $org  = $orgs[$key] ?? null;
            $cnpj = $org['cpfCnpj'] ?? null;

            $changed = false;

            if ($cnpj) {
                $solicitante             = $ticket->solicitante;
                $solicitante['cpf_cnpj'] = $cnpj;
                $ticket->solicitante     = $solicitante;
                $changed = true;

                if (!$ticket->customer_id) {
                    $customerId = Customer::where('cgc', $cnpj)->value('id');
                    if ($customerId) {
                        $ticket->customer_id = $customerId;
                    }
                }
            }

            if ($changed) {
                $ticket->save();
                $updatedTickets++;
            }
        });

        $this->info("{$updatedTickets} tickets atualizados com CNPJ.");

        // ── 3. Revincular apontamentos (timesheets) ao projeto correto ────────
        $this->info('Revinculando apontamentos Movidesk ao projeto correto...');

        $serviceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')
            ->first();

        if (!$serviceType) {
            $this->warn('ServiceType sustentação não encontrado — relink de apontamentos ignorado.');
            return self::SUCCESS;
        }

        // Mapa customer_id → project_id (projeto de sustentação ativo por cliente)
        $projectMap = Project::where('service_type_id', $serviceType->id)
            ->get()
            ->filter(fn($p) => $p->isActive())
            ->mapWithKeys(fn($p) => [$p->customer_id => $p->id])
            ->toArray();

        $defaultProjectId  = (int) SystemSetting::get('movidesk_default_project_id');
        $defaultCustomerId = (int) SystemSetting::get('movidesk_default_customer_id');
        $relinkCount       = 0;

        // Itera todos os movidesk_tickets com customer_id resolvido
        MovideskTicket::whereNotNull('customer_id')
            ->whereNotNull('ticket_id')
            ->orderBy('id')
            ->each(function (MovideskTicket $mt) use ($projectMap, $defaultProjectId, $defaultCustomerId, &$relinkCount) {
                $correctCustomerId = $mt->customer_id;
                $correctProjectId  = $projectMap[$correctCustomerId] ?? $defaultProjectId;

                if (!$correctProjectId) return;

                $affected = Timesheet::where('ticket', $mt->ticket_id)
                    ->where('origin', 'webhook')
                    ->where(function ($q) use ($correctCustomerId, $correctProjectId) {
                        $q->where('customer_id', '!=', $correctCustomerId)
                          ->orWhere('project_id', '!=', $correctProjectId);
                    })
                    ->update([
                        'customer_id' => $correctCustomerId,
                        'project_id'  => $correctProjectId,
                    ]);

                $relinkCount += $affected;
            });

        $this->info("{$relinkCount} apontamentos revinculados ao projeto correto.");

        return self::SUCCESS;
    }
}
