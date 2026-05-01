<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NormalizeContractStateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function handle(): void
    {
        $fixed = 0;
        $created = 0;

        // Contratos sem snapshot — backfill
        Contract::whereNotIn('id', ContractFlowSnapshot::select('contract_id'))
            ->select(['id', 'status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'])
            ->chunk(100, function ($contracts) use (&$created) {
                foreach ($contracts as $contract) {
                    $projectStatus = $contract->project_id
                        ? Project::where('id', $contract->project_id)->value('status')
                        : null;

                    ContractFlowSnapshot::create([
                        'contract_id'        => $contract->id,
                        'status'             => $contract->status,
                        'kanban_status'      => $contract->kanban_status,
                        'sustentacao_column' => $contract->sustentacao_column,
                        'project_status'     => $projectStatus,
                        'project_id'         => $contract->project_id,
                        'category'           => $contract->categoria,
                        'version'            => 1,
                    ]);
                    $created++;
                }
            });

        // Snapshots divergentes — comparar campo a campo via JOIN
        $divergent = DB::table('contract_flow_snapshots as s')
            ->join('contracts as c', 'c.id', '=', 's.contract_id')
            ->where(function ($q) {
                $q->whereColumn('c.status', '!=', 's.status')
                  ->orWhereColumn('c.kanban_status', '!=', 's.kanban_status')
                  ->orWhereColumn('c.project_id', '!=', 's.project_id')
                  ->orWhereColumn('c.categoria', '!=', 's.category')
                  ->orWhere(function ($sq) {
                      // sustentacao_column pode ser null em ambos — comparar só quando diferem
                      $sq->whereRaw('COALESCE(c.sustentacao_column, \'\') != COALESCE(s.sustentacao_column, \'\')');
                  });
            })
            ->select('c.id', 'c.status', 'c.kanban_status', 'c.sustentacao_column', 'c.project_id', 'c.categoria')
            ->get();

        foreach ($divergent as $row) {
            Log::warning('NormalizeContractState: divergência detectada e corrigida', [
                'contract_id' => $row->id,
            ]);

            $projectStatus = $row->project_id
                ? Project::where('id', $row->project_id)->value('status')
                : null;

            DB::table('contract_flow_snapshots')
                ->where('contract_id', $row->id)
                ->update([
                    'status'             => $row->status,
                    'kanban_status'      => $row->kanban_status,
                    'sustentacao_column' => $row->sustentacao_column,
                    'project_status'     => $projectStatus,
                    'project_id'         => $row->project_id,
                    'category'           => $row->categoria,
                    'version'            => DB::raw('version + 1'),
                    'inconsistency_count' => DB::raw('inconsistency_count + 1'),
                ]);

            $fixed++;
        }

        if ($created > 0 || $fixed > 0) {
            Log::info('NormalizeContractState: concluído', [
                'snapshots_criados' => $created,
                'divergencias_corrigidas' => $fixed,
            ]);
        }
    }
}
