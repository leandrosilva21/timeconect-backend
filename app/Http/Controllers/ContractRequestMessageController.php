<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractRequestMessageController extends Controller
{

    public function index(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = $contractRequest->messages()
            ->with(['author:id,name', 'attachments'])
            ->orderBy('created_at');

        // Cliente: depois que a requisição virou projeto (req_decided_at preenchido),
        // mensagens novas da equipe ficam invisíveis. Cliente só vê o histórico até a transição.
        if ($user->isCliente() && $contractRequest->req_decided_at) {
            $query->where('created_at', '<=', $contractRequest->req_decided_at);
        }

        return response()->json($query->get());
    }

    public function store(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // Cliente só interage enquanto é requisição. Quando vira projeto
        // (req_decision setado em requestPlanDecision), chat fica read-only
        // pro cliente — internos (admin/coord) seguem podendo comentar.
        if ($user->isCliente() && $contractRequest->req_decision !== null) {
            return response()->json([
                'message' => 'A requisição virou projeto. O chat ficou disponível apenas para histórico.',
            ], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:2000',
            'files'   => 'nullable|array|max:10',
            'files.*' => 'file|max:20480',
        ]);

        $text = $request->input('message', '');
        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        $msg = ContractRequestMessage::create([
            'contract_request_id' => $contractRequest->id,
            'user_id'             => $user->id,
            'message'             => $text,
        ]);

        // FASE 11.7 (PR 7b) — Upload de anexos 100% via camada Attachment.
        if ($request->hasFile('files')) {
            $service = app(\App\Attachments\AttachmentService::class);
            foreach ($request->file('files') as $file) {
                $path = $file->store('req-message-attachments', 'public');
                $service->registerExisting($user, [
                    'entity_type'   => 'REQUEST_MESSAGE',
                    'entity_id'     => $msg->id,
                    'category'      => 'attachment',
                    'storage_path'  => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }
        }

        $msg->load(['author:id,name', 'attachments']);

        return response()->json($msg, 201);
    }

    public function downloadAttachment(Request $request, ContractRequestMessage $message, Attachment $attachment): mixed
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $message->request?->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // FASE 11.7 (PR 7b) — valida vínculo polimórfico.
        if ($attachment->entity_type !== 'REQUEST_MESSAGE' || (int) $attachment->entity_id !== (int) $message->id) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        return Storage::disk('public')->download($attachment->storage_path, $attachment->original_name);
    }

    public function mentionableUsers(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json([], 403);
        }

        $users = User::whereIn('type', ['admin', 'coordenador'])
            ->where('enabled', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }
}
