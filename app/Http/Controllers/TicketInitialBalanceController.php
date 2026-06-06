<?php

namespace App\Http\Controllers;

use App\Models\TicketInitialBalance;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TicketInitialBalanceController extends Controller
{
    private function authorize(): ?JsonResponse
    {
        $u = Auth::user();
        if (!$u || (!$u->isAdmin() && !$u->isCoordenador())) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        return null;
    }

    /**
     * Resolve cliente/projeto a partir do ticket (busca em timesheets).
     * 404 se não houver apontamento — frontend bloqueia gravação.
     */
    public function lookup(Request $request): JsonResponse
    {
        if ($r = $this->authorize()) return $r;

        $ticket = trim((string) $request->query('ticket', ''));
        if (!preg_match('/^[0-9]{5}$/', $ticket)) {
            return response()->json(['message' => 'Ticket inválido — esperado 5 dígitos'], 422);
        }

        // Pega o apontamento mais recente desse ticket pra resolver customer+project.
        $ts = Timesheet::with(['customer:id,name', 'project:id,name,code,customer_id'])
            ->where('ticket', $ticket)
            ->orderByDesc('id')
            ->first();

        if (!$ts) {
            return response()->json([
                'message' => 'Ticket não encontrado nos apontamentos do Time Conect',
            ], 404);
        }

        return response()->json([
            'ticket'   => $ticket,
            'customer' => $ts->customer ? ['id' => $ts->customer->id, 'name' => $ts->customer->name] : null,
            'project'  => $ts->project  ? ['id' => $ts->project->id, 'name' => $ts->project->name, 'code' => $ts->project->code] : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($r = $this->authorize()) return $r;

        $q = TicketInitialBalance::query()
            ->with(['customer:id,name', 'project:id,name,code', 'creator:id,name']);

        if ($request->filled('ticket'))      $q->where('ticket', 'ilike', '%' . $request->ticket . '%');
        if ($request->filled('customer_id')) $q->where('customer_id', $request->customer_id);
        if ($request->filled('project_id'))  $q->where('project_id', $request->project_id);

        $perPage = min((int) $request->get('pageSize', 50), 200);
        $items = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'items'   => $items->items(),
            'hasNext' => $items->hasMorePages(),
            'total'   => $items->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if ($r = $this->authorize()) return $r;
        $row = TicketInitialBalance::with(['customer:id,name', 'project:id,name,code', 'creator:id,name'])->findOrFail($id);
        return response()->json($row);
    }

    public function store(Request $request): JsonResponse
    {
        if ($r = $this->authorize()) return $r;

        $data = $request->validate([
            'ticket'          => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'customer_id'     => 'required|integer|exists:customers,id',
            'project_id'      => 'required|integer|exists:projects,id',
            'initial_minutes' => 'required|integer|min:0|max:9999999',
            'description'     => 'nullable|string|max:2000',
        ]);

        // Re-valida o lookup pra travar manipulação: ticket precisa existir
        // em timesheets E o customer/project enviados batem com algum apontamento.
        $matchExists = Timesheet::where('ticket', $data['ticket'])
            ->where('customer_id', $data['customer_id'])
            ->where('project_id', $data['project_id'])
            ->exists();
        if (!$matchExists) {
            return response()->json([
                'message' => 'Ticket+cliente+projeto não correspondem a nenhum apontamento existente',
            ], 422);
        }

        // Unicidade lógica (ticket, customer_id) — partial index cobre, mas
        // queremos mensagem clara em vez de erro 500 de constraint.
        $existing = TicketInitialBalance::where('ticket', $data['ticket'])
            ->where('customer_id', $data['customer_id'])
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Já existe saldo inicial cadastrado para este ticket neste cliente. Edite o existente.',
                'existing_id' => $existing->id,
            ], 409);
        }

        $data['created_by'] = Auth::id();
        $row = TicketInitialBalance::create($data);
        $row->load(['customer:id,name', 'project:id,name,code', 'creator:id,name']);
        return response()->json($row, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($r = $this->authorize()) return $r;

        $row = TicketInitialBalance::findOrFail($id);
        $data = $request->validate([
            'initial_minutes' => 'sometimes|integer|min:0|max:9999999',
            'description'     => 'sometimes|nullable|string|max:2000',
        ]);
        $row->update($data);
        $row->load(['customer:id,name', 'project:id,name,code', 'creator:id,name']);
        return response()->json($row);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($r = $this->authorize()) return $r;
        $row = TicketInitialBalance::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }
}
