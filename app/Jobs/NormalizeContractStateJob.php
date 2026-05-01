<?php

namespace App\Jobs;

use App\Listeners\ContractEventListener;
use App\Models\Contract;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NormalizeContractStateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue   = 'low';
    public $tries   = 1;
    public $timeout = 120;

    public function handle(): void
    {
        $fixed   = 0;
        $created = 0;

        // Backfill: contratos sem snapshot (sempre verificar todos)
        Contract::whereNotIn('id', ContractFlowSnapshot::select('contract_id'))
            ->select(['id', 'status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'])
            ->chunk(100, function ($contracts) use (&$created) {
                foreach ($contracts as $contract) {
                    $projectStatus = $contract->project_id
                        ? Project::where('id', $contract->project_id)->value('status')
                        : null;

                    [$lastEventId, $lastSeq] = $this->latestEvent($contract->id);

                    ContractFlowSnapshot::create([
                        'contract_id'        => $contract->id,
                        'status'             => $contract->status,
                        'kanban_status'      => $contract->kanban_status,
                        'sustentacao_column' => $contract->sustentacao_column,
                        'project_status'     => $projectStatus,
                        'project_id'         => $contract->project_id,
                        'category'           => $contract->categoria,
                        'version'            => 1,
                        'last_event_id'      => $lastEventId,
                        'last_sequence'      => $lastSeq,
                    ]);
                    Cache::forget(ContractEventListener::cacheKey($contract->id));
                    $created++;
                }
            });

        // Amostragem: instáveis (inconsistency_count > 0) sempre; estáveis = 20% aleatório
        $unstableIds = DB::table('contract_flow_snapshots')
            ->where('inconsistency_count', '>', 0)
            ->pluck('contract_id')
            ->toArray();

        $totalStable  = DB::table('contract_flow_snapshots')->where('inconsistency_count', 0)->count();
        $sampleSize   = max(10, (int) ceil($totalStable * 0.2));
        $stableIds    = DB::table('contract_flow_snapshots')
            ->where('inconsistency_count', 0)
            ->inRandomOrder()
            ->limit($sampleSize)
            ->pluck('contract_id')
            ->toArray();

        $contractIdsToCheck = array_unique(array_merge($unstableIds, $stableIds));

        // Divergência: comparar campo a campo apenas nos contratos amostrados
        $divergent = DB::table('contract_flow_snapshots as s')
            ->join('contracts as c', 'c.id', '=', 's.contract_id')
            ->whereIn('c.id', $contractIdsToCheck)
            ->where(function ($q) {
                $q->whereColumn('c.status', '!=', 's.status')
                  ->orWhereColumn('c.kanban_status', '!=', 's.kanban_status')
                  ->orWhereColumn('c.project_id', '!=', 's.project_id')
                  ->orWhereColumn('c.categoria', '!=', 's.category')
                  ->orWhere(fn($sq) => $sq->whereRaw(
                      "COALESCE(c.sustentacao_column, '') != COALESCE(s.sustentacao_column, '')"
                  ));
            })
            ->select('c.id', 'c.status', 'c.kanban_status', 'c.sustentacao_column',
                     'c.project_id', 'c.categoria', 's.last_event_id as snap_last_event_id')
            ->get();

        foreach ($divergent as $row) {
            [$lastEventId, $lastSeq] = $this->latestEvent($row->id);

            // Não sobrescrever snapshot mais recente que o evento mais recente conhecido
            if ($lastEventId !== null && $row->snap_last_event_id !== null && $row->snap_last_event_id >= $lastEventId) {
                continue;
            }

            Log::warning('NormalizeContractState: divergência corrigida', ['contract_id' => $row->id]);

            $projectStatus = $row->project_id
                ? Project::where('id', $row->project_id)->value('status')
                : null;

            DB::table('contract_flow_snapshots')
                ->where('contract_id', $row->id)
                ->update([
                    'status'              => $row->status,
                    'kanban_status'       => $row->kanban_status,
                    'sustentacao_column'  => $row->sustentacao_column,
                    'project_status'      => $projectStatus,
                    'project_id'          => $row->project_id,
                    'category'            => $row->categoria,
                    'last_event_id'       => $lastEventId,
                    'last_sequence'       => $lastSeq,
                    'version'             => DB::raw('version + 1'),
                    'inconsistency_count' => DB::raw('inconsistency_count + 1'),
                ]);

            Cache::forget(ContractEventListener::cacheKey($row->id));
            $fixed++;
        }

        if ($created > 0 || $fixed > 0) {
            Log::info('NormalizeContractState: concluído', [
                'criados'   => $created,
                'corrigidos' => $fixed,
                'amostrados' => count($contractIdsToCheck),
            ]);
        }

        $this->checkAlerts();
    }

    private function checkAlerts(): void
    {
        $highRisk = DB::table('contract_flow_snapshots')->where('inconsistency_count', '>', 5)->count();
        if ($highRisk > 0) {
            Log::error('NormalizeContractState: inconsistency_count alto', ['count' => $highRisk]);
        }

        $pendingJobs = DB::table('jobs')->count();
        if ($pendingJobs > 50) {
            Log::warning('NormalizeContractState: queue acumulada', ['pending' => $pendingJobs]);
        }

        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(24))->count();
        if ($failedJobs > 0) {
            Log::error('NormalizeContractState: jobs falhando 24h', ['failed' => $failedJobs]);
        }
    }

    private function latestEvent(int $contractId): array
    {
        $row = DB::table('contract_events')
            ->where('contract_id', $contractId)
            ->orderByDesc('id')
            ->select(['id', 'sequence_number'])
            ->first();

        return [$row?->id, (int) ($row?->sequence_number ?? 0)];
    }
}
