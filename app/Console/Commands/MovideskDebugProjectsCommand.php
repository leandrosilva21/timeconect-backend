<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MovideskOrganization;
use App\Models\Project;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class MovideskDebugProjectsCommand extends Command
{
    protected $signature   = 'movidesk:debug-projects';
    protected $description = 'Diagnóstico: mostra o mapa customer→projeto de sustentação e projetos do HUB ACCOUNTING';

    public function handle(): int
    {
        // ── 1. Projetos de sustentação encontrados pelo sync-orgs ────────────
        $projs = Project::join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%');
            })
            ->select('projects.id', 'projects.name', 'projects.status',
                     'projects.customer_id', 'service_types.code as st_code', 'service_types.name as st_name')
            ->get();

        $activeProjs = $projs->filter(fn($p) => $p->isActive());

        $this->info("=== PROJETOS NO MAPA ({$activeProjs->count()} ativos / {$projs->count()} total) ===");
        $this->table(
            ['status', 'nome', 'customer_id', 'st_code', 'st_name'],
            $projs->map(fn($p) => [
                $p->status, mb_substr($p->name, 0, 40), $p->customer_id, $p->st_code, $p->st_name
            ])->toArray()
        );

        // ── 2. Default project ───────────────────────────────────────────────
        $def = SystemSetting::get('movidesk_default_project_id');
        $this->info('DEFAULT PROJECT ID: ' . ($def ?: '(não configurado)'));

        // ── 3. Todos os projetos do HUB ACCOUNTING ───────────────────────────
        $hub = Customer::where('name', 'ilike', '%hub accounting%')
            ->orWhere('company_name', 'ilike', '%hub accounting%')
            ->first();

        if (!$hub) {
            $this->warn('HUB ACCOUNTING: cliente não encontrado.');
            return self::SUCCESS;
        }

        $this->info("=== HUB ACCOUNTING (customer_id={$hub->id}) ===");

        $hubProjs = Project::leftJoin('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where('projects.customer_id', $hub->id)
            ->select('projects.id', 'projects.name', 'projects.status',
                     'service_types.code as st_code', 'service_types.name as st_name')
            ->get();

        if ($hubProjs->isEmpty()) {
            $this->warn('Nenhum projeto encontrado para HUB ACCOUNTING.');
        } else {
            $this->table(
                ['id', 'nome', 'status', 'st_code', 'st_name'],
                $hubProjs->map(fn($p) => [
                    $p->id, mb_substr($p->name, 0, 40), $p->status, $p->st_code ?? '(null)', $p->st_name ?? '(null)'
                ])->toArray()
            );
        }

        // ── 4. Orgs Movidesk SEM customer_id vinculado ──────────────────────────
        $unlinked = MovideskOrganization::whereNull('customer_id')->get();
        if ($unlinked->isNotEmpty()) {
            $this->warn('=== ORGS SEM VÍNCULO (' . $unlinked->count() . ') — use movidesk:link-org para corrigir ===');
            $this->table(
                ['id', 'nome', 'cnpj'],
                $unlinked->map(fn($o) => [$o->id, mb_substr($o->name, 0, 45), $o->cnpj ?: '(sem CNPJ)'])->toArray()
            );
        } else {
            $this->info('Todas as orgs têm customer_id vinculado.');
        }

        return self::SUCCESS;
    }
}
