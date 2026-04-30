<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Timesheet;
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

        // ── 1. Salva/atualiza movidesk_organizations com customer_id por CNPJ ──
        $this->info('Sincronizando tabela de organizações...');

        // Índice: CNPJ normalizado → Customer (para match por CNPJ)
        $customersByCnpj = Customer::whereNotNull('cgc')
            ->get()
            ->keyBy(fn($c) => preg_replace('/[^0-9]/', '', $c->cgc));

        foreach ($orgs as $org) {
            $cnpj       = $org['cpfCnpj'] ?? null;
            $customerId = null;

            if ($cnpj) {
                $cnpjNorm   = preg_replace('/[^0-9]/', '', $cnpj);
                // 1º: tenta pelo CNPJ
                $customerId = $customersByCnpj[$cnpjNorm]->id ?? null;
            }

            // 2º: fallback por nome (para orgs sem CNPJ ou CNPJ não cadastrado)
            if (!$customerId) {
                $customerId = Customer::where('name', $org['name'])
                    ->orWhere('company_name', $org['name'])
                    ->value('id');
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

        // ── 2. Atualiza movidesk_tickets usando movidesk_organizations (CNPJ) ──
        // Índice: nome da org (lowercase) → movidesk_organization (com customer_id)
        $this->info('Atualizando customer_id nos tickets via CNPJ...');

        $orgByName = MovideskOrganization::whereNotNull('customer_id')
            ->get()
            ->keyBy(fn($o) => strtolower(trim($o->name)));

        $updatedTickets = 0;

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(
            function (MovideskTicket $ticket) use ($orgs, $orgByName, &$updatedTickets) {
                $orgName = trim($ticket->solicitante['organization'] ?? '');
                if (!$orgName) return;

                $key     = strtolower($orgName);
                $changed = false;

                // Atualiza cpf_cnpj no solicitante
                $org  = $orgs[$key] ?? null;
                $cnpj = $org['cpfCnpj'] ?? null;
                if ($cnpj) {
                    $solicitante             = $ticket->solicitante;
                    $solicitante['cpf_cnpj'] = $cnpj;
                    $ticket->solicitante     = $solicitante;
                    $changed = true;
                }

                // Atualiza customer_id usando movidesk_organizations (resolvido por CNPJ)
                $movideskOrg = $orgByName[$key] ?? null;
                if ($movideskOrg && $movideskOrg->customer_id !== $ticket->customer_id) {
                    $ticket->customer_id = $movideskOrg->customer_id;
                    $changed = true;
                }

                if ($changed) {
                    $ticket->save();
                    $updatedTickets++;
                }
            }
        );

        $this->info("{$updatedTickets} tickets atualizados.");

        // ── 3. Revincular timesheets ao projeto de sustentação correto ─────────
        $this->info('Revinculando apontamentos Movidesk ao projeto correto...');

        // Mesma lógica do SustentacaoController: ilike '%sustenta%'
        $projectMap = Project::join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->select('projects.*')
            ->get()
            ->filter(fn($p) => $p->isActive())
            ->mapWithKeys(fn($p) => [$p->customer_id => $p->id])
            ->toArray();

        $defaultProjectId = (int) SystemSetting::get('movidesk_default_project_id');
        $relinkCount      = 0;

        MovideskTicket::whereNotNull('customer_id')
            ->whereNotNull('ticket_id')
            ->orderBy('id')
            ->each(function (MovideskTicket $mt) use ($projectMap, $defaultProjectId, &$relinkCount) {
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
