<?php

namespace App\Listeners;

use App\Events\ContractEventCreated;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ContractEventListener implements ShouldQueue
{
    public bool $afterCommit = true;
    public int  $tries       = 3;
    public array $backoff    = [5, 15, 30];

    private const SNAPSHOT_TYPES  = ['status_changed', 'kanban_moved'];
    private const SNAPSHOT_FIELDS = ['status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'];

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
            if ($snap && $snap->last_event_id !== null && $snap->last_event_id >= $ce->id) return;

            // Ordem: sequência já processada ou mais recente — evita regressão de estado
            if ($snap && $snap->last_sequence >= $ce->sequence_number) return;

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
                    ContractFlowSnapshot::create(array_merge($data, [
                        'contract_id' => $contract->id,
                        'version'     => 1,
                    ]));
                    return;
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    usleep(random_int(10_000, 50_000));
                    continue;
                }
            }

            // Optimistic lock — só aplica se version não mudou desde a leitura
            $affected = ContractFlowSnapshot::where('contract_id', $contract->id)
                ->where('version', $snap->version)
                ->update(array_merge($data, ['version' => $snap->version + 1]));

            if ($affected === 1) return;

            // Conflito de versão — outro evento ganhou; retentar com estado fresco
            usleep(random_int(10_000, 50_000));
        }

        Log::warning('ContractEventListener: conflito de versão após retries', [
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
