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

        $updatedTickets = 0;

        MovideskTicket::whereNotNull('solicitante')->orderBy('id')->each(
            function (MovideskTicket $ticket) use ($orgs, $orgByName, $customersByCnpj, &$updatedTickets) {
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

                if ($cpfCnpjNorm) {
                    $newCustomerId = $customersByCnpj[$cpfCnpjNorm]->id ?? null;
                }
                if (!$newCustomerId && $orgName) {
                    $newCustomerId = ($orgByName[$key] ?? null)?->customer_id;
                }

                if ($newCustomerId && $newCustomerId !== $ticket->customer_id) {
                    $ticket->customer_id = $newCustomerId;
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
