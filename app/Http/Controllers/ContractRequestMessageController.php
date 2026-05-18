<?php

namespace App\Http\Controllers;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use App\Models\ContractRequestMessageAttachment;
use App\Models\ContractRequestMessageMention;
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

        if (!$this->clienteCanAccess($user, $contractRequest)) {
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

        if (!$this->clienteCanAccess($user, $contractRequest)) {
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

        // Parser @-mention token @[id:Nome] (espelha ProjectMessageController)
        $this->persistMentions($msg);

        // Fase card-envolvidos: notifica envolvidos do card (cliente sai automaticamente
        // se req_decided_at preenchido). Best-effort — falha em mail não bloqueia chat.
        try {
            $this->dispatchChatNotification($contractRequest, $msg, $user);
        } catch (\Throwable $e) {
            \Log::warning('chat notif req falhou', ['req_id' => $contractRequest->id, 'err' => $e->getMessage()]);
        }

        return response()->json($msg, 201);
    }

    private function persistMentions(ContractRequestMessage $msg): void
    {
        // Candidatos = usuários com acesso à req (cliente da req + admins/coords)
        $req = $msg->request ?? \App\Models\ContractRequest::find($msg->contract_request_id);
        $candidates = \App\Models\User::query()
            ->select('id', 'name')
            ->where(function ($q) use ($req) {
                $q->whereIn('type', ['admin', 'coordenador', 'consultor', 'parceiro_admin', 'administrativo']);
                if ($req?->customer_id) {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'cliente')->where('customer_id', $req->customer_id));
                }
            })
            ->get();

        $userIds = \App\Services\MentionParser::extract((string) $msg->message, $candidates);
        foreach ($userIds as $uid) {
            ContractRequestMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => $uid,
            ]);
        }
    }

    private function dispatchChatNotification(ContractRequest $req, ContractRequestMessage $msg, User $author): void
    {
        $recipients = app(CardEnvolvidoService::class)
            ->recipientsForChat(CardEnvolvido::TYPE_REQUEST, $req->id, $author->id);

        if ($recipients->isEmpty()) return;

        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $cardUrl = $base . '/contratos/pipeline?req=' . $req->id;
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
            'admin' => 'Admin', 'coordenador' => 'Coordenador', 'consultor' => 'Consultor',
            'cliente' => 'Cliente', 'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo',
            default => 'Equipe',
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

        if (!$this->clienteCanAccess($user, $contractRequest)) {
            return response()->json([], 403);
        }

        $users = User::whereIn('type', ['admin', 'coordenador'])
            ->where('enabled', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * Cliente acessa a requisição/chat se for do mesmo customer E (criador OU watcher).
     * Internos (admin/coord/consultor) sempre passam.
     */
    private function clienteCanAccess(?User $user, ContractRequest $req): bool
    {
        if (!$user) return false;
        if (!$user->isCliente()) return true;
        if ($user->customer_id !== $req->customer_id) return false;
        if ((int) $req->created_by_id === (int) $user->id) return true;
        return $req->watchers()->where('user_id', $user->id)->exists();
    }
}
