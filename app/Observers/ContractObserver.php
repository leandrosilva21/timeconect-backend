<?php

namespace App\Observers;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ContractObserver
{
    protected array $trackedFields = [
        'project_name',
        'status',
        'kanban_status',
        'sustentacao_column',
        'project_id',
        'categoria',
        'customer_id',
        'contract_type_id',
        'horas_contratadas',
        'hourly_rate',
        'project_value',
        'kanban_coordinator_id',
    ];

    public function created(Contract $contract): void
    {
        $this->upsertSnapshot($contract);
        $this->logEvent($contract, 'status_changed', 'status', null, $contract->status);
    }

    public function updated(Contract $contract): void
    {
        if (!Auth::id()) return;

        foreach ($this->trackedFields as $field) {
            if ($contract->wasChanged($field)) {
                $this->logEvent(
                    $contract,
                    'field_changed',
                    $field,
                    $contract->getOriginal($field),
                    $contract->$field
                );
            }
        }

        if ($contract->wasChanged(['status', 'kanban_status', 'sustentacao_column', 'project_id', 'categoria'])) {
            $this->upsertSnapshot($contract);
        }

        $this->validateConsistency($contract);
    }

    private function upsertSnapshot(Contract $contract): void
    {
        try {
            $projectStatus = $contract->project_id
                ? Project::find($contract->project_id)?->status
                : null;

            ContractFlowSnapshot::updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'status'             => $contract->status,
                    'kanban_status'      => $contract->kanban_status,
                    'sustentacao_column' => $contract->sustentacao_column,
                    'project_status'     => $projectStatus,
                    'project_id'         => $contract->project_id,
                    'category'           => $contract->categoria,
                    'updated_by'         => Auth::id(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('ContractObserver: falha ao atualizar snapshot', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function logEvent(Contract $contract, string $type, ?string $field,
                               mixed $from, mixed $to, ?array $meta = null): void
    {
        try {
            ContractEvent::create([
                'contract_id'  => $contract->id,
                'event_type'   => $type,
                'field'        => $field,
                'from_value'   => $from !== null ? (string) $from : null,
                'to_value'     => $to   !== null ? (string) $to   : null,
                'triggered_by' => Auth::id(),
                'meta'         => $meta,
            ]);
        } catch (\Throwable $e) {
            Log::error('ContractObserver: falha ao registrar evento', [
                'contract_id' => $contract->id,
                'event_type'  => $type,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function validateConsistency(Contract $contract): void
    {
        if ($contract->project_id) {
            $project = Project::find($contract->project_id);
            if (!$project) {
                Log::warning('ContractConsistency: project_id aponta para projeto inexistente', [
                    'contract_id' => $contract->id,
                    'project_id'  => $contract->project_id,
                ]);
            }
        }

        if ($contract->kanban_status === 'alocado' && !$contract->project_id) {
            Log::warning('ContractConsistency: kanban_status=alocado sem project_id', [
                'contract_id' => $contract->id,
            ]);
        }

        $snap = ContractFlowSnapshot::where('contract_id', $contract->id)->first();
        if ($snap && $snap->kanban_status !== $contract->kanban_status) {
            Log::warning('ContractConsistency: snapshot divergente do estado real', [
                'contract_id'     => $contract->id,
                'snapshot_kanban' => $snap->kanban_status,
                'contract_kanban' => $contract->kanban_status,
            ]);
        }
    }
}
