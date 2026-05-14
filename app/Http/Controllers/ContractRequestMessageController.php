<?php

namespace App\Http\Controllers;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use App\Models\ContractRequestMessageAttachment;
use App\Models\User;
use App\Notifications\CardChatMessageNotification;
use App\Services\CardEnvolvidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        // Notifica envolvidos (exceto o autor). Cliente sai automaticamente se req_decided_at preenchido.
        $this->dispatchChatNotification($contractRequest, $msg, $user);

        return response()->json($msg, 201);
    }

    private function dispatchChatNotification(ContractRequest $req, ContractRequestMessage $msg, User $author): void
    {
        $recipients = app(CardEnvolvidoService::class)
            ->recipientsForChat(CardEnvolvido::TYPE_REQUEST, $req->id, $author->id);

        if ($recipients->isEmpty()) return;

        $base = config('app.url');
        $cardUrl = rtrim($base, '/') . '/contratos/pipeline?req=' . $req->id;
        $openUrl = $cardUrl . '#chat';
        $code = $req->code ?? ('REQ-' . str_pad((string) $req->id, 6, '0', STR_PAD_LEFT));
        $title = $req->title ?? ($req->subject ?? 'Requisição');
        $excerpt = Str::limit($msg->message ?? '', 280);

        foreach ($recipients as $r) {
            Notification::route('mail', $r['email'])->notify(new CardChatMessageNotification(
                cardType:       CardEnvolvido::TYPE_REQUEST,
                cardCode:       $code,
                cardTitle:      $title,
                authorName:     $author->name,
                authorRole:     $this->userRoleLabel($author),
                messageExcerpt: $excerpt,
                openUrl:        $openUrl,
                cardUrl:        $cardUrl,
                recipientName:  $r['display_name'],
            ));
        }
    }

    private function userRoleLabel(User $u): string
    {
        return match ($u->type) {
            'admin'           => 'Admin',
            'coordenador'     => 'Coordenador',
            'consultor'       => 'Consultor',
            'cliente'         => 'Cliente',
            'parceiro_admin'  => 'Parceiro',
            'administrativo'  => 'Administrativo',
            default           => 'Equipe',
        };
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

        // Agora vem da lista de envolvidos do card, respeitando bloqueio do cliente após decisão.
        $q = CardEnvolvido::forCard(CardEnvolvido::TYPE_REQUEST, $contractRequest->id)
            ->with('user:id,name,email,type');

        if (!empty($contractRequest->req_decided_at)) {
            $q->internal();
        }

        $list = $q->get()
            ->filter(fn($e) => $e->user_id !== null)  // @menção exige user real (não email externo)
            ->map(fn($e) => ['id' => $e->user_id, 'name' => $e->user?->name])
            ->values();

        return response()->json($list);
    }
}
