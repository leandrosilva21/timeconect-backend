<?php

namespace App\Listeners;

use App\Events\ContractEventCreated;
use App\Models\Contract;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

class ContractEventListener
{
    private const SNAPSHOT_TYPES  = ['status_changed', 'kanban_moved'];
    private const SNAPSHOT_FIELDS = ['status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'];

    public function handle(ContractEventCreated $event): void
    {
        $ce = $event->contractEvent;

        $isRelevant = in_array($ce->event_type, self::SNAPSHOT_TYPES)
            || in_array($ce->field, self::SNAPSHOT_FIELDS);

        if (!$isRelevant) return;

        $contract = Contract::find($ce->contract_id);
        if (!$contract) return;

        $this->updateSnapshot($contract, $ce->sequence_number);
    }

    private function updateSnapshot(Contract $contract, int $sequence, int $maxRetries = 3): void
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $snap = ContractFlowSnapshot::where('contract_id', $contract->id)->first();

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
            ];

            if (!$snap) {
                try {
                    ContractFlowSnapshot::create(array_merge($data, [
                        'contract_id' => $contract->id,
                        'version'     => 1,
                    ]));
                    return;
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Corrida de inserção — retentar com update
                    usleep(random_int(10_000, 50_000));
                    continue;
                }
            }

            // Optimistic lock: só aplica se version não mudou desde a leitura
            $affected = ContractFlowSnapshot::where('contract_id', $contract->id)
                ->where('version', $snap->version)
                ->update(array_merge($data, ['version' => $snap->version + 1]));

            if ($affected === 1) return;

            // Conflito de versão — outro evento ganhou a corrida; retentar
            usleep(random_int(10_000, 50_000));
        }

        Log::warning('ContractEventListener: conflito de versão após retries', [
            'contract_id' => $contract->id,
            'sequence'    => $sequence,
        ]);
    }
}
