<?php

namespace App\Http\Controllers;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CardEnvolvidoController extends Controller
{
    /**
     * GET /{cardType}/{cardId}/envolvidos
     * cardType: contract-requests | projects
     */
    public function index(string $cardType, int $cardId): JsonResponse
    {
        [$type, $card] = $this->resolveCard($cardType, $cardId);
        $this->authorizeView($card);

        $envolvidos = CardEnvolvido::forCard($type, $cardId)
            ->with(['user:id,name,email,type,customer_id', 'addedBy:id,name'])
            ->orderBy('side')
            ->orderBy('id')
            ->get()
            ->map(fn($e) => [
                'id'             => $e->id,
                'side'           => $e->side,
                'user_id'        => $e->user_id,
                'external_email' => $e->external_email,
                'display_name'   => $e->display_name,
                'email'          => $e->notification_email,
                'user_type'      => $e->user?->type,
                'added_by_name'  => $e->addedBy?->name,
                'added_at'       => $e->created_at,
            ]);

        return response()->json($envolvidos);
    }

    /**
     * POST /{cardType}/{cardId}/envolvidos
     */
    public function store(Request $request, string $cardType, int $cardId): JsonResponse
    {
        [$type, $card] = $this->resolveCard($cardType, $cardId);
        $this->authorizeManage($card);

        $data = $request->validate([
            'user_id'        => 'nullable|integer|exists:users,id',
            'external_email' => 'nullable|email|max:255',
            'side'           => 'required|in:internal,client',
        ]);

        if (empty($data['user_id']) && empty($data['external_email'])) {
            return response()->json(['message' => 'Informe user_id ou external_email.'], 422);
        }
        if (!empty($data['user_id']) && !empty($data['external_email'])) {
            return response()->json(['message' => 'Use user_id OU external_email, não os dois.'], 422);
        }

        // Validação cruzada: side coerente com tipo do user
        if (!empty($data['user_id'])) {
            $u = User::find($data['user_id']);
            $isClient = $u->type === 'cliente';
            if ($data['side'] === 'internal' && $isClient) {
                return response()->json(['message' => 'Usuário cliente não pode ser envolvido interno.'], 422);
            }
            if ($data['side'] === 'client' && !$isClient) {
                return response()->json(['message' => 'Usuário não-cliente não pode ser envolvido cliente.'], 422);
            }
        }

        // Idempotência: se já existe, retorna o existente
        $existing = CardEnvolvido::forCard($type, $cardId)
            ->where(function($q) use ($data) {
                if (!empty($data['user_id']))        $q->where('user_id', $data['user_id']);
                if (!empty($data['external_email'])) $q->where('external_email', $data['external_email']);
            })
            ->first();

        if ($existing) {
            return response()->json($existing, 200);
        }

        $envolvido = CardEnvolvido::create([
            'card_type'      => $type,
            'card_id'        => $cardId,
            'user_id'        => $data['user_id'] ?? null,
            'external_email' => $data['external_email'] ?? null,
            'side'           => $data['side'],
            'added_by'       => auth()->id(),
        ]);

        return response()->json($envolvido->load('user:id,name,email,type'), 201);
    }

    /**
     * GET /{cardType}/{cardId}/mention-candidates
     * Lista os envolvidos elegíveis pra @menção no chat.
     * Regras:
     *  - Cliente nunca é candidato em chat de projeto.
     *  - Após req_decided_at, cliente sai dos candidatos no chat de requisição.
     */
    public function mentionCandidates(string $cardType, int $cardId): JsonResponse
    {
        [$type, $card] = $this->resolveCard($cardType, $cardId);
        $this->authorizeView($card);

        $query = CardEnvolvido::forCard($type, $cardId)
            ->with('user:id,name,email,type');

        // Chat de projeto: zero candidato do lado cliente
        if ($type === CardEnvolvido::TYPE_PROJECT) {
            $query->internal();
        }

        // Requisição já decidida: cliente sai dos candidatos
        if ($type === CardEnvolvido::TYPE_REQUEST && !empty($card->req_decided_at)) {
            $query->internal();
        }

        $list = $query->get()->map(fn($e) => [
            'envolvido_id'   => $e->id,
            'user_id'        => $e->user_id,
            'external_email' => $e->external_email,
            'display_name'   => $e->display_name,
            'side'           => $e->side,
        ]);

        return response()->json($list);
    }

    public function destroy(string $cardType, int $cardId, int $id): JsonResponse
    {
        [$type, $card] = $this->resolveCard($cardType, $cardId);
        $this->authorizeManage($card);

        $envolvido = CardEnvolvido::forCard($type, $cardId)->where('id', $id)->firstOrFail();
        $envolvido->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Resolve cardType da URL ('contract-requests' ou 'projects') para enum + carrega o card.
     *
     * @return array{0:string,1:\Illuminate\Database\Eloquent\Model}
     */
    private function resolveCard(string $cardType, int $cardId): array
    {
        if ($cardType === 'contract-requests') {
            return [CardEnvolvido::TYPE_REQUEST, ContractRequest::findOrFail($cardId)];
        }
        if ($cardType === 'projects') {
            return [CardEnvolvido::TYPE_PROJECT, Project::findOrFail($cardId)];
        }
        abort(404, 'Tipo de card desconhecido');
    }

    private function authorizeView($card): void
    {
        $u = auth()->user();
        if (!$u) abort(401);
        // Clientes só veem seu próprio customer
        if ($u->isCliente()) {
            $customerId = $card->customer_id ?? null;
            if ($customerId && $u->customer_id !== $customerId) abort(403);
        }
    }

    private function authorizeManage($card): void
    {
        $u = auth()->user();
        if (!$u) abort(401);
        // Apenas perfis internos podem gerenciar envolvidos
        if ($u->isCliente()) abort(403, 'Cliente não pode gerenciar envolvidos.');
    }
}
