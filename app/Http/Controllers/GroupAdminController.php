<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\Inbox\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administração de grupos operacionais (Configurações → BOT Minutor → Grupos).
 * Acesso restrito a admin ou executivo.
 */
class GroupAdminController extends Controller
{
    /** Grupos operacionais padrão da ERPSERV. */
    public const DEFAULT_GROUPS = [
        'Coordenadores',
        'Sustentação',
        'Financeiro',
        'Customer Success',
        'Projetos',
        'Diretoria',
    ];

    public function __construct(protected ConversationService $conversations)
    {
    }

    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        $u = $request->user();
        if ($u->type === 'admin' || $u->is_executive) {
            return null;
        }
        return response()->json(['message' => 'Apenas administradores ou executivos podem gerenciar grupos.'], 403);
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $groups = Conversation::query()
            ->where('type', ConversationType::Group->value)
            ->withCount('participants')
            ->orderBy('title')
            ->get(['id', 'title', 'created_by', 'created_at', 'last_message_at']);

        return response()->json([
            'data' => $groups->map(fn (Conversation $g) => [
                'id'             => $g->id,
                'title'          => $g->title,
                'members_count'  => $g->participants_count,
                'created_by'     => $g->created_by,
                'created_at'     => $g->created_at?->toIso8601String(),
                'last_message_at'=> $g->last_message_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $group = Conversation::where('type', ConversationType::Group->value)->findOrFail($id);

        $members = ConversationParticipant::where('conversation_id', $group->id)
            ->with('user:id,name,email,profile_photo')
            ->get();

        return response()->json([
            'data' => [
                'id'      => $group->id,
                'title'   => $group->title,
                'members' => $members->map(fn ($p) => [
                    'user_id'       => $p->user_id,
                    'role'          => $p->role,
                    'name'          => $p->user?->name,
                    'email'         => $p->user?->email,
                    'profile_photo' => $p->user?->profile_photo,
                    'is_creator'    => $p->user_id === (int) $group->created_by,
                ])->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $request->validate([
            'name'              => 'required|string|max:120',
            'participant_ids'   => 'nullable|array',
            'participant_ids.*' => 'integer|exists:users,id',
        ]);

        $group = $this->conversations->createGroup(
            $request->user(),
            $data['name'],
            $data['participant_ids'] ?? [],
        );

        return response()->json(['data' => ['id' => $group->id, 'title' => $group->title]], 201);
    }

    public function rename(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $request->validate(['name' => 'required|string|max:120']);
        $group = Conversation::where('type', ConversationType::Group->value)->findOrFail($id);
        $group->update(['title' => trim($data['name'])]);

        return response()->json(['data' => ['id' => $group->id, 'title' => $group->title]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $group = Conversation::where('type', ConversationType::Group->value)->findOrFail($id);
        // messages e participants têm cascadeOnDelete
        $group->delete();

        return response()->json(['deleted' => true]);
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $group = Conversation::where('type', ConversationType::Group->value)->findOrFail($id);

        ConversationParticipant::firstOrCreate(
            ['conversation_id' => $group->id, 'user_id' => $data['user_id']],
            ['role' => 'member', 'joined_at' => now()],
        );

        return $this->members($request, $id);
    }

    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $group = Conversation::where('type', ConversationType::Group->value)->findOrFail($id);

        if ($userId === (int) $group->created_by) {
            return response()->json(['message' => 'Não é possível remover o criador do grupo.'], 422);
        }

        ConversationParticipant::where('conversation_id', $group->id)
            ->where('user_id', $userId)
            ->delete();

        return $this->members($request, $id);
    }

    /**
     * Cria os grupos operacionais padrão que ainda não existem.
     * O criador (admin atual) entra como admin de cada grupo novo.
     */
    public function seedDefaults(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $user = $request->user();
        $existing = Conversation::where('type', ConversationType::Group->value)
            ->whereIn('title', self::DEFAULT_GROUPS)
            ->pluck('title')
            ->all();

        $created = [];
        foreach (self::DEFAULT_GROUPS as $name) {
            if (in_array($name, $existing, true)) continue;
            $g = $this->conversations->createGroup($user, $name, []);
            $created[] = ['id' => $g->id, 'title' => $g->title];
        }

        return response()->json([
            'data' => [
                'created'       => $created,
                'already_existed' => $existing,
            ],
        ]);
    }

    /**
     * Lista usuários para adicionar a um grupo (ativos, com filtro de busca).
     */
    public function availableUsers(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        $q = trim((string) $request->query('q', ''));
        $query = User::query()->select('id', 'name', 'email', 'profile_photo', 'type')
            ->where('enabled', true);

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return response()->json([
            'data' => $query->orderBy('name')->limit(50)->get(),
        ]);
    }
}
