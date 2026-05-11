<?php

namespace App\Http\Controllers;

use App\Models\TimesheetLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetLogController extends Controller
{
    /**
     * GET /api/v1/timesheets/{id}/logs
     */
    public function forTimesheet(int $id): JsonResponse
    {
        $logs = TimesheetLog::with('changedBy:id,name,email')
            ->where('timesheet_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * Campos cuja mudança é considerada "fluxo de aprovação" (não edição de
     * conteúdo). Quando uma entrada de log SÓ mexe nesses campos, ela é
     * filtrada da listagem geral (/timesheet-logs) — continua visível no
     * histórico individual do apontamento (/timesheets/{id}/logs).
     */
    private const APPROVAL_ONLY_FIELDS = [
        'status', 'reviewed_at', 'reviewed_by', 'rejection_reason',
    ];

    /**
     * GET /api/v1/timesheet-logs
     * Filtros: user_id, project_id, customer_id, source, action, start_date, end_date, search
     * NÃO retorna entradas que são apenas fluxo de aprovação.
     */
    public function index(Request $request): JsonResponse
    {
        // Sem select restritivo no timesheet — accessors em $appends (status_display,
        // effort_hours, attachment_url) precisam de status, effort_minutes, attachment_path.
        $q = TimesheetLog::query()
            ->with([
                'changedBy:id,name',
                'timesheet' => fn ($q) => $q->withTrashed(),
                'timesheet.user:id,name',
                'timesheet.project:id,code,name',
                'timesheet.customer:id,name',
            ]);

        if ($request->filled('user_id')) {
            $q->whereHas('timesheet', fn ($s) => $s->where('user_id', $request->integer('user_id')));
        }
        if ($request->filled('project_id')) {
            $q->whereHas('timesheet', fn ($s) => $s->where('project_id', $request->integer('project_id')));
        }
        if ($request->filled('customer_id')) {
            $q->whereHas('timesheet', fn ($s) => $s->where('customer_id', $request->integer('customer_id')));
        }
        if ($request->filled('source')) {
            $q->where('source', $request->string('source'));
        }
        if ($request->filled('action')) {
            $q->where('action', $request->string('action'));
        }
        if ($request->filled('changed_by')) {
            $q->where('changed_by', $request->integer('changed_by'));
        }
        if ($request->filled('start_date')) {
            $q->whereDate('created_at', '>=', $request->date('start_date'));
        }
        if ($request->filled('end_date')) {
            $q->whereDate('created_at', '<=', $request->date('end_date'));
        }

        // Filtro pré-paginação (Postgres JSONB): exclui entradas 'updated' cujos
        // únicos campos mudados são de aprovação. Filtrar pós-paginação fazia
        // a página vir vazia quando os 50 mais recentes eram todos aprovação.
        $q->where(function ($w) {
            $w->where('action', '!=', 'updated')
              ->orWhereRaw(
                  "EXISTS (SELECT 1 FROM jsonb_object_keys(changes::jsonb) k WHERE k NOT IN (?, ?, ?, ?))",
                  self::APPROVAL_ONLY_FIELDS
              );
        });

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json(
            $q->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }
}
