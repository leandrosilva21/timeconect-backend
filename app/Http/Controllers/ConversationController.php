<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserPresence;
use App\Services\Inbox\ConversationService;
use App\Services\Inbox\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        protected ConversationService $conversations,
        protected PresenceService $presence,
    ) {
    }

    /**
     * POST /api/v1/conversations
     * body:
     *   { "type": "direct", "user_id": <int> }                                  -> cria/retorna DM
     *   { "type": "group",  "name": "...", "participant_ids": [<int>, ...] }    -> cria grupo
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'type'              => 'required|in:direct,group',
            'user_id'           => 'required_if:type,direct|integer|exists:users,id',
            'name'              => 'required_if:type,group|nullable|string|max:120',
            'participant_ids'   => 'nullable|array',
            'participant_ids.*' => 'integer|exists:users,id',
        ]);

        if ($data['type'] === 'direct') {
            $other = User::findOrFail($data['user_id']);
            if ($other->id === $user->id) {
                return response()->json(['message' => 'Não é possível abrir DM consigo mesmo.'], 422);
            }
            $conv = $this->conversations->createDirect($user, $other);
        } else {
            $conv = $this->conversations->createGroup($user, $data['name'], $data['participant_ids'] ?? []);
        }

        $conv->load('participants.user:id,name,profile_photo');

        return response()->json(['data' => $this->serialize($conv)], 201);
    }

    /**
     * POST /api/v1/conversations/{id}/participants
     * body: { "user_id": <int> }
     */
    public function addParticipant(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $conv = Conversation::findOrFail($id);
        $new  = User::findOrFail($data['user_id']);

        try {
            $this->conversations->addParticipant($conv, $new, $user);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $conv->load('participants.user:id,name,profile_photo');
        return response()->json(['data' => $this->serialize($conv)]);
    }

    /**
     * DELETE /api/v1/conversations/{id}/participants/{userId}
     */
    public function removeParticipant(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::findOrFail($id);

        try {
            $this->conversations->removeParticipant($conv, $userId, $user);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['removed' => true]);
    }

    /**
     * GET /api/v1/users/for-chat?q=...
     * Lista usuários ativos (sem o próprio) com presença efetiva.
     */
    public function usersForChat(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        $query = User::query()
            ->select('id', 'name', 'email', 'profile_photo')
            ->where('enabled', true)
            ->where('id', '!=', $user->id);

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like);
            });
        }

        $users = $query->orderBy('name')->limit(50)->get();

        $presences = UserPresence::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return response()->json([
            'data' => $users->map(function (User $u) use ($presences) {
                $p = $presences->get($u->id);
                return [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'email'         => $u->email,
                    'profile_photo' => $u->profile_photo,
                    'presence'      => [
                        'status'       => $this->presence->effectiveStatus($p)->value,
                        'last_seen_at' => $p?->last_seen_at?->toIso8601String(),
                    ],
                ];
            })->all(),
        ]);
    }

    private function serialize(Conversation $c): array
    {
        return [
            'id'               => $c->id,
            'type'             => $c->type->value,
            'title'            => $c->title,
            'created_by'       => $c->created_by,
            'last_message_at'  => $c->last_message_at?->toIso8601String(),
            'participants'     => $c->participants->map(fn ($p) => [
                'user_id' => $p->user_id,
                'role'    => $p->role,
                'user'    => $p->user ? [
                    'id'            => $p->user->id,
                    'name'          => $p->user->name,
                    'profile_photo' => $p->user->profile_photo,
                ] : null,
            ])->values()->all(),
        ];
    }
}
