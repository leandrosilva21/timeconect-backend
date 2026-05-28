<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractMessage;
use App\Models\ContractMessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractMessageController extends Controller
{
    use \App\Attachments\Concerns\DualWritesMessageAttachments;

    public function index(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = $contract->messages()->with(['author:id,name', 'attachments'])->orderBy('created_at');

        if ($user->isCliente()) {
            $query->where('visibility', 'client');
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:5000',
            'files'   => 'nullable|array|max:10',
            'files.*' => 'file|max:20480',
        ]);

        $text = $request->input('message', '');

        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        $visibility = $user->isCliente() ? 'client' : ($user->isAdmin() ? 'internal' : $request->input('visibility', 'internal'));

        $msg = ContractMessage::create([
            'contract_id' => $contract->id,
            'user_id'     => $user->id,
            'message'     => $text,
            'visibility'  => $visibility,
        ]);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('contract-message-attachments', 'public');
                ContractMessageAttachment::create([
                    'message_id'    => $msg->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $path,
                    'file_size'     => $file->getSize(),
                    'mime_type'     => $file->getMimeType(),
                ]);
                // FASE 11 — dual-write (não-fatal).
                $this->dualWriteMessageAttachment('CONTRACT_MESSAGE', $msg->id, $file, $path);
            }
        }

        $msg->load(['author:id,name', 'attachments']);

        return response()->json($msg, 201);
    }

    public function downloadAttachment(Request $request, ContractMessage $message, ContractMessageAttachment $attachment): mixed
    {
        $user = $request->user();

        if (!$this->canAccess($user, $message->contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if ($user->isCliente() && $message->visibility !== 'client') {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function mentionableUsers(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if (!$this->canAccess($user, $contract)) {
            return response()->json([], 403);
        }

        $ids = collect();

        // Executivo de conta: primeiro do contrato, depois do cliente
        if ($contract->executivo_conta_id) {
            $ids->push($contract->executivo_conta_id);
        } elseif ($contract->customer_id) {
            $customer = \App\Models\Customer::select('id', 'executive_id')->find($contract->customer_id);
            if ($customer?->executive_id) {
                $ids->push($customer->executive_id);
            }
        }

        // Coordenador kanban do contrato
        if ($contract->kanban_coordinator_id) {
            $ids->push($contract->kanban_coordinator_id);
        }

        // Usuários cliente vinculados ao cliente do contrato
        if ($contract->customer_id) {
            $clientUserIds = User::where('type', 'cliente')
                ->where('customer_id', $contract->customer_id)
                ->where('enabled', true)
                ->pluck('id');
            $ids = $ids->merge($clientUserIds);
        }

        // Exclui o próprio usuário da lista
        $ids = $ids->unique()->reject(fn($id) => $id === $user->id)->values();

        $users = User::whereIn('id', $ids)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function markRead(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $visibilityFilter = $user->isCliente() ? ['client'] : ['internal', 'client'];

        $unreadCount = ContractMessage::where('contract_id', $contract->id)
            ->where('user_id', '!=', $user->id)
            ->whereIn('visibility', $visibilityFilter)
            ->whereRaw(
                "created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = ? LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id, $contract->id]
            )
            ->count();

        DB::statement(
            "INSERT INTO contract_user_reads (user_id, contract_id, last_read_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (user_id, contract_id)
             DO UPDATE SET last_read_at = GREATEST(contract_user_reads.last_read_at, EXCLUDED.last_read_at)",
            [$user->id, $contract->id]
        );

        return response()->json(['marked' => $unreadCount]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ContractMessage::query()
            ->where('user_id', '!=', $user->id)
            ->whereRaw(
                "contract_messages.created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = contract_messages.contract_id LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id]
            );

        if ($user->isCliente()) {
            $query->where('visibility', 'client')
                  ->whereHas('contract', fn($q) => $q->where('customer_id', $user->customer_id));
        } elseif ($user->isCoordenador()) {
            $query->whereHas('contract', fn($q) =>
                $q->where('kanban_coordinator_id', $user->id)
            );
        }

        $rows = $query
            ->with(['contract:id,project_name,customer_id', 'contract.customer:id,name', 'author:id,name'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($msg) => [
                'id'            => $msg->id,
                'contract_id'   => $msg->contract_id,
                'project_name'  => $msg->contract?->project_name ?? '—',
                'customer_name' => $msg->contract?->customer?->name ?? '—',
                'author_name'   => $msg->author?->name ?? '—',
                'preview'       => mb_strimwidth(preg_replace('/@\[\d+:([^\]]+)\]/', '@$1', $msg->message ?? ''), 0, 80, '…'),
                'created_at'    => $msg->created_at,
            ]);

        return response()->json($rows);
    }

    public function unreadContracts(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ContractMessage::query()
            ->where('user_id', '!=', $user->id)
            ->whereRaw(
                "contract_messages.created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = contract_messages.contract_id LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id]
            );

        if ($user->isCliente()) {
            $query->where('visibility', 'client')
                  ->whereHas('contract', fn($q) => $q->where('customer_id', $user->customer_id));
        } elseif ($user->isCoordenador()) {
            $query->whereHas('contract', fn($q) =>
                $q->where('kanban_coordinator_id', $user->id)
            );
        }

        $contractIds = $query->pluck('contract_id')->unique()->values();

        return response()->json(['contract_ids' => $contractIds]);
    }

    private function canAccess(User $user, ?Contract $contract): bool
    {
        if (!$contract) return false;
        if ($user->isAdmin()) return true;
        if ($user->isCoordenador()) return true;
        if ($user->isCliente() && $user->customer_id === $contract->customer_id) return true;
        return false;
    }
}
