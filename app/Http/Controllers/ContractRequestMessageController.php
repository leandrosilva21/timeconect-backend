<?php

namespace App\Http\Controllers;

use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use App\Models\ContractRequestMessageAttachment;
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

        $messages = $contractRequest->messages()
            ->with(['author:id,name', 'attachments'])
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
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

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('req-message-attachments', 'public');
                ContractRequestMessageAttachment::create([
                    'message_id'    => $msg->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $path,
                    'file_size'     => $file->getSize(),
                    'mime_type'     => $file->getMimeType(),
                ]);
            }
        }

        $msg->load(['author:id,name', 'attachments']);

        return response()->json($msg, 201);
    }

    public function downloadAttachment(Request $request, ContractRequestMessage $message, ContractRequestMessageAttachment $attachment): mixed
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $message->request?->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
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
