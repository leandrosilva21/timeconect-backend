<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FechamentoParceiro;
use App\Models\Partner;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechamentoParceiroController extends Controller
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function period(string $yearMonth): array
    {
        $from = "{$yearMonth}-01";
        $to   = Carbon::parse($from)->endOfMonth()->toDateString();
        return [$from, $to];
    }

    private function effectiveHourlyRate(float $hourlyRate, string $rateType): float
    {
        return ($rateType === 'monthly' && $hourlyRate > 0)
            ? round($hourlyRate / 180, 4)
            : $hourlyRate;
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $yearMonth = $request->query('year_month');

        $partners = Partner::whereRaw('"active" = true')
            ->orderBy('name')
            ->get(['id', 'name', 'pricing_type', 'hourly_rate']);

        $fechamentos = $yearMonth
            ? FechamentoParceiro::where('year_month', $yearMonth)
                ->with('closedByUser:id,name')
                ->get()
                ->keyBy('partner_id')
            : collect();

        $data = $partners->map(function ($partner) use ($fechamentos) {
            $f = $fechamentos->get($partner->id);
            return [
                'partner_id'     => $partner->id,
                'nome'           => $partner->name,
                'pricing_type'   => $partner->pricing_type,
                'hourly_rate'    => (float) ($partner->hourly_rate ?? 0),
                'status'         => $f?->status ?? 'sem_registro',
                'total_horas'    => (float) ($f?->total_horas ?? 0),
                'total_despesas' => (float) ($f?->total_despesas ?? 0),
                'total_a_pagar'  => (float) ($f?->total_a_pagar ?? 0),
                'closed_at'      => $f?->closed_at?->toISOString(),
                'closed_by_name' => $f?->closedByUser?->name,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ─── Consultores ─────────────────────────────────────────────────────────

    public function consultores(string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_consultores) {
            return response()->json(['data' => $fechamento->snapshot_consultores, 'from_snapshot' => true]);
        }

        $partner = Partner::findOrFail($partnerId);
        $data    = $this->consultoresData($partner, $yearMonth);

        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Despesas ────────────────────────────────────────────────────────────

    public function despesas(string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($fechamento?->isClosed() && $fechamento->snapshot_despesas) {
            return response()->json(['data' => $fechamento->snapshot_despesas, 'from_snapshot' => true]);
        }

        $data = $this->despesasData((int) $partnerId, $yearMonth);
        return response()->json(['data' => $data, 'from_snapshot' => false]);
    }

    // ─── Fechar ──────────────────────────────────────────────────────────────

    public function fechar(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::firstOrNew([
            'partner_id' => $partnerId,
            'year_month' => $yearMonth,
        ]);

        if ($fechamento->exists && $fechamento->isClosed()) {
            return response()->json(['message' => 'Fechamento já está encerrado.'], 422);
        }

        $partner     = Partner::findOrFail($partnerId);
        $consultores = $this->consultoresData($partner, $yearMonth);
        $despesas    = $this->despesasData((int) $partnerId, $yearMonth);

        $totalHoras    = round(collect($consultores)->sum('horas'), 2);
        $totalServicos = round(collect($consultores)->sum('total'), 2);
        $totalDespesas = round(collect($despesas)->sum('valor'), 2);

        $fechamento->fill([
            'status'               => 'closed',
            'snapshot_consultores' => $consultores,
            'snapshot_despesas'    => $despesas,
            'total_horas'          => $totalHoras,
            'total_despesas'       => $totalDespesas,
            'total_a_pagar'        => round($totalServicos + $totalDespesas, 2),
            'closed_at'            => now(),
            'closed_by'            => $request->user()->id,
            'notes'                => $request->input('notes'),
        ])->save();

        return response()->json(['message' => "Fechamento do parceiro para {$yearMonth} encerrado.", 'fechamento' => $fechamento]);
    }

    // ─── Reabrir ─────────────────────────────────────────────────────────────

    public function reabrir(Request $request, string $partnerId, string $yearMonth): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão para reabrir fechamentos.'], 403);
        }

        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $fechamento->update([
            'status'               => 'open',
            'closed_at'            => null,
            'closed_by'            => null,
            'snapshot_consultores' => null,
            'snapshot_despesas'    => null,
        ]);

        return response()->json(['message' => "Fechamento do parceiro reaberto para {$yearMonth}."]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function consultoresData(Partner $partner, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $users = User::where('partner_id', $partner->id)
            ->where('type', 'parceiro_admin')
            ->where('enabled', true)
            ->get();

        $isFixed      = $partner->pricing_type === Partner::PRICING_FIXED;
        $partnerRate  = (float) ($partner->hourly_rate ?? 0);

        $rows = [];
        foreach ($users as $user) {
            $minutos = Timesheet::where('user_id', $user->id)
                ->whereBetween('date', [$from, $to])
                ->whereNotIn('status', [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED])
                ->whereNull('deleted_at')
                ->sum('effort_minutes');

            $horas = round($minutos / 60, 2);

            if ($isFixed) {
                $valorHora = $partnerRate;
            } else {
                $valorHora = $this->effectiveHourlyRate(
                    (float) ($user->hourly_rate ?? 0),
                    $user->rate_type ?? 'hourly'
                );
            }

            $rows[] = [
                'user_id'              => $user->id,
                'nome'                 => $user->name,
                'horas'                => $horas,
                'rate_type'            => $user->rate_type ?? 'hourly',
                'valor_hora'           => $valorHora,
                'pricing_type_parceiro'=> $partner->pricing_type,
                'total'                => round($horas * $valorHora, 2),
            ];
        }

        return $rows;
    }

    private function despesasData(int $partnerId, string $yearMonth): array
    {
        [$from, $to] = $this->period($yearMonth);

        $userIds = User::where('partner_id', $partnerId)
            ->where('type', 'parceiro_admin')
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        $excludeStatuses = [Expense::STATUS_ADJUSTMENT_REQUESTED, Expense::STATUS_REJECTED];

        return Expense::with([
            'user:id,name',
            'project:id,name,code',
            'category:id,name',
        ])
            ->whereIn('user_id', $userIds)
            ->whereNotIn('status', $excludeStatuses)
            ->where('is_paid', false)
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->map(fn ($e) => [
                'id'          => $e->id,
                'data'        => $e->expense_date->format('Y-m-d'),
                'descricao'   => $e->description,
                'categoria'   => $e->category->name ?? '—',
                'colaborador' => $e->user->name ?? '—',
                'projeto'     => $e->project->name ?? '—',
                'valor'       => (float) $e->amount,
                'status'      => $e->status,
            ])
            ->toArray();
    }

    // ─── Apontamentos ─────────────────────────────────────────────────────────

    public function apontamentos(string $partnerId, string $yearMonth): JsonResponse
    {
        $fechamento = FechamentoParceiro::where('partner_id', $partnerId)
            ->where('year_month', $yearMonth)
            ->first();

        // Snapshots não guardam apontamentos — sempre busca ao vivo
        [$from, $to] = $this->period($yearMonth);

        $userIds = User::where('partner_id', $partnerId)
            ->where('type', 'parceiro_admin')
            ->where('enabled', true)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $excludeStatuses = [Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_REJECTED];

        $rows = Timesheet::with([
            'user:id,name',
            'project:id,name,code,contract_type_id,customer_id',
            'project.contractType:id,name,code',
            'project.customer:id,name',
        ])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_titulo', 'movidesk_tickets.solicitante as ticket_solicitante')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket')
            ->whereIn('timesheets.user_id', $userIds)
            ->whereBetween('timesheets.date', [$from, $to])
            ->whereNotIn('timesheets.status', $excludeStatuses)
            ->whereNull('timesheets.deleted_at')
            ->orderBy('timesheets.user_id')
            ->orderBy('timesheets.date')
            ->get()
            ->map(function ($t) {
                $solicitanteRaw = $t->ticket_solicitante;
                if (is_string($solicitanteRaw)) $solicitanteRaw = json_decode($solicitanteRaw, true);
                $solicitante = is_array($solicitanteRaw) ? ($solicitanteRaw['name'] ?? null) : null;

                $ct = $t->project?->contractType;

                return [
                    'id'                   => $t->id,
                    'data'                 => $t->date->format('Y-m-d'),
                    'user_id'              => $t->user_id,
                    'consultor'            => $t->user->name ?? '—',
                    'cliente'              => $t->project->customer->name ?? '—',
                    'projeto'              => $t->project->name ?? '—',
                    'projeto_codigo'       => $t->project->code ?? '—',
                    'tipo_contrato_code'   => $ct?->code ?? 'outros',
                    'tipo_contrato_nome'   => $ct?->name ?? 'Outros',
                    'horas'                => round($t->effort_minutes / 60, 2),
                    'status'               => $t->status,
                    'ticket'               => $t->ticket,
                    'titulo'               => $t->ticket_titulo,
                    'solicitante'          => $solicitante,
                    'observacao'           => $t->observation,
                ];
            });

        return response()->json(['data' => $rows]);
    }
}
