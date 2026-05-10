<?php

namespace App\Observers;

use App\Models\Timesheet;
use App\Models\TimesheetLog;
use Illuminate\Support\Facades\Auth;

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

        $this->createLog($timesheet, 'updated', $changes);
    }

    public function deleted(Timesheet $timesheet): void
    {
        // Para soft-delete, $timesheet->deleted_at acabou de ser setado.
        // Para hard-delete (não acontece em timesheets, mas defensivo), changes inclui snapshot.
        $this->createLog($timesheet, 'deleted', [
            'deleted_at' => ['old' => null, 'new' => optional($timesheet->deleted_at)->toIso8601String() ?? now()->toIso8601String()],
        ]);
    }

    public function restored(Timesheet $timesheet): void
    {
        $this->createLog($timesheet, 'restored', [
            'deleted_at' => ['old' => optional($timesheet->getOriginal('deleted_at'))->toString() ?? 'unknown', 'new' => null],
        ]);
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

    private function createLog(Timesheet $timesheet, string $action, array $changes): void
    {
        TimesheetLog::create([
            'timesheet_id' => $timesheet->id,
            'changed_by'   => Auth::id(),
            'source'       => $this->detectSource($timesheet),
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
