<?php

namespace App\Observers;

use App\Models\Timesheet;
use App\Models\TimesheetLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TimesheetObserver
{
    /**
     * Campos ignorados no diff — ruído de sistema, não interesse de auditoria.
     */
    private const IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'attachment_path',
    ];

    /**
     * Campos cujas edições manuais devem proteger o apontamento contra reprocess do Movidesk.
     */
    private const PROTECTED_ON_MANUAL_EDIT = ['customer_id', 'project_id'];

    public function updated(Timesheet $timesheet): void
    {
        // Soft-delete chega em deleted() — não duplicar aqui
        if ($timesheet->wasChanged('deleted_at') && $timesheet->deleted_at !== null) {
            return;
        }

        $changes = $this->buildDiff($timesheet);
        if (empty($changes)) {
            return;
        }

        $source = $this->detectSource($timesheet);
        $this->createLog($timesheet, 'updated', $changes, $source);

        // Edição manual de customer_id ou project_id em apontamento vindo do Movidesk:
        // marca manual_project_edit = true para o reprocess automático respeitar a escolha.
        if ($source === 'manual' && !$timesheet->manual_project_edit) {
            $touched = array_intersect(self::PROTECTED_ON_MANUAL_EDIT, array_keys($changes));
            if (!empty($touched)) {
                // Update direto via DB::table para não disparar o observer recursivamente
                DB::table('timesheets')->where('id', $timesheet->id)->update([
                    'manual_project_edit' => true,
                    'updated_at'          => now(),
                ]);
            }
        }
    }

    public function deleted(Timesheet $timesheet): void
    {
        $this->createLog($timesheet, 'deleted', [
            'deleted_at' => ['old' => null, 'new' => optional($timesheet->deleted_at)->toIso8601String() ?? now()->toIso8601String()],
        ], $this->detectSource($timesheet));
    }

    public function restored(Timesheet $timesheet): void
    {
        $this->createLog($timesheet, 'restored', [
            'deleted_at' => ['old' => optional($timesheet->getOriginal('deleted_at'))->toString() ?? 'unknown', 'new' => null],
        ], $this->detectSource($timesheet));
    }

    private function buildDiff(Timesheet $timesheet): array
    {
        $diff = [];
        foreach ($timesheet->getDirty() as $field => $newValue) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $oldValue = $timesheet->getOriginal($field);
            // Comparação solta (==) cobre Carbon vs string, int vs float, etc.
            if ($oldValue == $newValue) {
                continue;
            }
            $diff[$field] = [
                'old' => $this->normalize($oldValue),
                'new' => $this->normalize($newValue),
            ];
        }
        return $diff;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        return $value;
    }

    private function createLog(Timesheet $timesheet, string $action, array $changes, ?string $source = null): void
    {
        TimesheetLog::create([
            'timesheet_id' => $timesheet->id,
            'changed_by'   => Auth::id(),
            'source'       => $source ?? $this->detectSource($timesheet),
            'action'       => $action,
            'changes'      => $changes,
        ]);
    }

    /**
     * Source pode ser sobrescrito pelo caller setando $timesheet->_logSource antes do save.
     * Caso contrário, infere por contexto.
     */
    private function detectSource(Timesheet $timesheet): string
    {
        $explicit = $timesheet->_logSource ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        if (Auth::check()) {
            return 'manual';
        }
        if (app()->runningInConsole()) {
            return 'system';
        }
        return 'unknown';
    }
}
