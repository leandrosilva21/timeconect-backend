<?php

namespace App\Listeners;

use App\Events\ContractEventCreated;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContractEventListener implements ShouldQueue
{
    public string $queue      = 'high';
    public bool   $afterCommit = true;
    public int    $tries       = 3;
    public array  $backoff     = [5, 15, 30];

    private const SNAPSHOT_TYPES  = ['status_changed', 'kanban_moved'];
    private const SNAPSHOT_FIELDS = ['status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'];
    public  const CACHE_TTL       = 30; // segundos

    public static function cacheKey(int $contractId): string
    {
        return "contract_snapshot_{$contractId}";
    }

    public function handle(ContractEventCreated $event): void
    {
        $ce = $event->contractEvent;

        if (!$this->isRelevant($ce)) return;

        $contract = Contract::find($ce->contract_id);
        if (!$contract) return;

        $this->updateSnapshot($contract, $ce);
    }

    private function updateSnapshot(Contract $contract, ContractEvent $ce, int $maxRetries = 3): void
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $snap = ContractFlowSnapshot::where('contract_id', $contract->id)->first();

            // Idempotência: este evento (ou mais recente) já foi processado
            if ($snap && $snap->last_event_id !== null && $snap->last_event_id >= $ce->id) {
                Log::debug('ContractEventListener: ignorado (idempotência)', [
                    'contract_id' => $contract->id, 'event_id' => $ce->id,
                ]);
                return;
            }

            // Ordem: sequência já processada — evita regressão de estado
            if ($snap && $snap->last_sequence >= $ce->sequence_number) {
                Log::debug('ContractEventListener: ignorado (ordem)', [
                    'contract_id' => $contract->id, 'sequence' => $ce->sequence_number,
                ]);
                return;
            }

            $projectStatus = $contract->project_id
                ? Project::where('id', $contract->project_id)->value('status')
                : null;

            $data = [
                'status'             => $contract->status,
                'kanban_status'      => $contract->kanban_status,
                'sustentacao_column' => $contract->sustentacao_column,
                'project_status'     => $projectStatus,
                'project_id'         => $contract->project_id,
                'category'           => $contract->categoria,
                'last_event_id'      => $ce->id,
                'last_sequence'      => $ce->sequence_number,
            ];

            if (!$snap) {
                try {
                    $created = ContractFlowSnapshot::create(array_merge($data, [
                        'contract_id' => $contract->id,
                        'version'     => 1,
                    ]));
                    // Write-through: nunca cachear null
                    Cache::put(self::cacheKey($contract->id), $created, self::CACHE_TTL);
                    Log::info('ContractEventListener: snapshot criado', ['contract_id' => $contract->id, 'event_id' => $ce->id]);
                    return;
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    usleep(random_int(10_000, 50_000));
                    continue;
                }
            }

            // Optimistic lock + snapshot guard — ambos devem passar
            $affected = ContractFlowSnapshot::where('contract_id', $contract->id)
                ->where('version', $snap->version)
                ->where('last_sequence', '<', $ce->sequence_number) // snapshot guard
                ->update(array_merge($data, ['version' => $snap->version + 1]));

            if ($affected === 1) {
                // Write-through: cachear o estado atual (nunca null)
                $fresh = ContractFlowSnapshot::where('contract_id', $contract->id)->first();
                if ($fresh) {
                    Cache::put(self::cacheKey($contract->id), $fresh, self::CACHE_TTL);
                }
                Log::info('ContractEventListener: snapshot atualizado', [
                    'contract_id' => $contract->id,
                    'event_id'    => $ce->id,
                    'sequence'    => $ce->sequence_number,
                    'version'     => $snap->version + 1,
                    'attempt'     => $attempt + 1,
                ]);
                return;
            }

            Log::debug('ContractEventListener: conflito de versão', [
                'contract_id' => $contract->id, 'attempt' => $attempt + 1,
            ]);
            usleep(random_int(10_000, 50_000));
        }

        Log::warning('ContractEventListener: conflito após todos os retries', [
            'contract_id' => $contract->id,
            'event_id'    => $ce->id,
            'sequence'    => $ce->sequence_number,
        ]);
    }

    private function isRelevant(ContractEvent $ce): bool
    {
        return in_array($ce->event_type, self::SNAPSHOT_TYPES)
            || in_array($ce->field, self::SNAPSHOT_FIELDS);
    }
}
