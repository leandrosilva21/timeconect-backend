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
     * GET /api/v1/timesheet-logs
     * Filtros: user_id, project_id, customer_id, source, action, start_date, end_date, search
     */
    public function index(Request $request): JsonResponse
    {
        $q = TimesheetLog::query()
            ->with([
                'changedBy:id,name',
                'timesheet' => fn ($q) => $q->withTrashed()->select('id', 'date', 'user_id', 'project_id', 'customer_id'),
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

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json(
            $q->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }
}
