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

        // ── 2. Atualiza movidesk_tickets usando CNPJ ──────────────────────────
        $this->info('Atualizando customer_id nos tickets via CNPJ...');

        // Índice por nome (lowercase) → movidesk_organization (fallback)
        $orgByName = MovideskOrganization::whereNotNull('customer_id')
            ->get()
            ->keyBy(fn($o) => strtolower(trim($o->name)));

        $updatedTickets  = 0;
        $ticketsByCustomer = []; // log: customer_name → qtd vinculada

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(
            function (MovideskTicket $ticket) use ($orgs, $orgByName, $customersByCnpj, &$updatedTickets, &$ticketsByCustomer) {
                $orgName = trim($ticket->solicitante['organization'] ?? '');
                $key     = strtolower($orgName);
                $changed = false;

                // Enriquece cpf_cnpj via fetchOrganizations (se disponível pelo nome)
                $org  = $orgs[$key] ?? null;
                $cnpj = $org['cpfCnpj'] ?? null;
                if ($cnpj && ($ticket->solicitante['cpf_cnpj'] ?? null) !== $cnpj) {
                    $solicitante             = $ticket->solicitante;
                    $solicitante['cpf_cnpj'] = $cnpj;
                    $ticket->solicitante     = $solicitante;
                    $changed = true;
                }

                // Resolve customer_id:
                // 1º) CNPJ já gravado em solicitante['cpf_cnpj'] → busca em customers
                // 2º) Fallback: nome da org → movidesk_organizations
                $cpfCnpjNorm   = preg_replace('/[^0-9]/', '', $ticket->solicitante['cpf_cnpj'] ?? '');
                $newCustomerId = null;
                $method        = null;

                if ($cpfCnpjNorm) {
                    $customer = $customersByCnpj[$cpfCnpjNorm] ?? null;
                    if ($customer) {
                        $newCustomerId = $customer->id;
                        $method        = 'CNPJ';
                    }
                }
                if (!$newCustomerId && $orgName) {
                    $movideskOrg = $orgByName[$key] ?? null;
                    if ($movideskOrg) {
                        $newCustomerId = $movideskOrg->customer_id;
                        $method        = 'Nome';
                    }
                }

                if ($newCustomerId && $newCustomerId !== $ticket->customer_id) {
                    $ticket->customer_id = $newCustomerId;
                    $changed = true;
                }

                if ($changed) {
                    $ticket->save();
                    $updatedTickets++;
                    $logKey = ($orgName ?: '(sem org)') . ($method ? " [{$method}]" : '');
                    $ticketsByCustomer[$logKey] = ($ticketsByCustomer[$logKey] ?? 0) + 1;
                }
            }
        );

        $this->info("{$updatedTickets} tickets atualizados.");

        if (!empty($ticketsByCustomer)) {
            arsort($ticketsByCustomer);
            $this->table(
                ['Organização (método)', 'Tickets atualizados'],
                collect($ticketsByCustomer)->map(fn($count, $org) => [mb_substr($org, 0, 55), $count])->values()->toArray()
            );
        }

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

        $defaultProjectId  = (int) SystemSetting::get('movidesk_default_project_id');
        $relinkCount       = 0;
        $relinkByProject   = []; // log: "Cliente → Projeto" → qtd apontamentos

        // Mapa auxiliar para nomes (customer_id → nome, project_id → nome)
        $customerNames = Customer::pluck('name', 'id')->toArray();
        $projectNames  = Project::pluck('name', 'id')->toArray();

        MovideskTicket::whereNotNull('customer_id')
            ->whereNotNull('ticket_id')
            ->orderBy('id')
            ->each(function (MovideskTicket $mt) use (
                $projectMap, $defaultProjectId,
                $customerNames, $projectNames,
                &$relinkCount, &$relinkByProject
            ) {
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

                if ($affected > 0) {
                    $relinkCount += $affected;
                    $customerName = $customerNames[$correctCustomerId] ?? "Cliente #{$correctCustomerId}";
                    $projectName  = $projectNames[$correctProjectId]  ?? "Projeto #{$correctProjectId}";
                    $logKey       = "{$customerName} → {$projectName}";
                    $relinkByProject[$logKey] = ($relinkByProject[$logKey] ?? 0) + $affected;
                }
            });

        $this->info("{$relinkCount} apontamentos revinculados ao projeto correto.");

        if (!empty($relinkByProject)) {
            arsort($relinkByProject);
            $this->table(
                ['Cliente → Projeto', 'Apontamentos revinculados'],
                collect($relinkByProject)->map(fn($count, $label) => [mb_substr($label, 0, 60), $count])->values()->toArray()
            );
        }

        return self::SUCCESS;
    }
}
