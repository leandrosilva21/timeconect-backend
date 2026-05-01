<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectMessageAttachment;
use App\Models\ProjectMessageMention;
use App\Models\ProjectMessageRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectMessageController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $messages = ProjectMessage::where('project_id', $project->id)
            ->with([
                'author:id,name,profile_photo',
                'attachments',
                'reads' => fn($q) => $q->where('user_id', $user->id),
            ])
            ->withExists(['mentions as is_mentioned' => fn($q) => $q->where('mentioned_user_id', $user->id)])
            // Clientes só veem mensagens marcadas como visíveis
            ->when($user->isCliente(), fn($q) => $q->where('visibility', 'client'))
            ->latest()
            ->paginate(50);

        return response()->json($messages);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message'    => 'nullable|string|max:5000',
            'priority'   => 'nullable|in:normal,high',
            'visibility' => 'nullable|in:internal,client',
            'files'      => 'nullable|array|max:10',
            'files.*'    => 'file|max:20480', // 20 MB por arquivo
        ]);

        $text = $request->input('message', '');
        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        // Clientes só enviam mensagens visíveis; admins sempre postam interno
        $visibility = $user->isCliente() ? 'client' : ($user->isAdmin() ? 'internal' : $request->input('visibility', 'internal'));

        $msg = ProjectMessage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'message'    => $text,
            'priority'   => $request->input('priority', 'normal'),
            'visibility' => $visibility,
        ]);

        // Parse mention tokens @[id:Name]
        preg_match_all('/@\[(\d+):([^\]]+)\]/', $text, $matches);
        foreach (array_unique($matches[1]) as $mentionedId) {
            ProjectMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => (int) $mentionedId,
            ]);
        }

        // Upload de anexos
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('message-attachments', 'public');
                ProjectMessageAttachment::create([
                    'message_id'    => $msg->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $path,
                    'file_size'     => $file->getSize(),
                    'mime_type'     => $file->getMimeType(),
                ]);
            }
        }

        $msg->load(['author:id,name,profile_photo', 'attachments']);
        $msg->is_mentioned = false;

        return response()->json($msg, 201);
    }

    public function downloadAttachment(Request $request, ProjectMessage $message, ProjectMessageAttachment $attachment): mixed
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $message->project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if ($user->isCliente() && $message->visibility !== 'client') {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function markRead(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = ProjectMessage::where('project_id', $project->id)
            ->whereDoesntHave('reads', fn($q) => $q->where('user_id', $user->id));

        if ($user->isCliente()) {
            $query->where('visibility', 'client');
        }

        $unreadIds = $query->pluck('id');

        if ($unreadIds->isNotEmpty()) {
            $rows = $unreadIds->map(fn($id) => ['message_id' => $id, 'user_id' => $user->id])->toArray();
            ProjectMessageRead::upsert($rows, ['message_id', 'user_id']);
        }

        return response()->json(['marked' => $unreadIds->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['count' => 0]);
        }

        $query = ProjectMessage::query();

        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $count = $query->whereDoesntHave('reads', fn($r) => $r->where('user_id', $user->id))->count();

        return response()->json(['count' => $count]);
    }

    public function unreadProjects(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['project_ids' => []]);
        }

        $query = ProjectMessage::query();

        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $projectIds = $query
            ->whereDoesntHave('reads', fn($r) => $r->where('user_id', $user->id))
            ->pluck('project_id')
            ->unique()
            ->values();

        return response()->json(['project_ids' => $projectIds]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json([]);
        }

        $query = ProjectMessage::query();

        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $rows = $query
            ->where('user_id', '!=', $user->id)
            ->whereDoesntHave('reads', fn($r) => $r->where('user_id', $user->id))
            ->with(['project:id,name,code', 'author:id,name'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($msg) => [
                'id'           => $msg->id,
                'project_id'   => $msg->project_id,
                'project_name' => $msg->project?->name ?? '—',
                'project_code' => $msg->project?->code ?? '',
                'author_name'  => $msg->author?->name ?? '—',
                'preview'      => mb_strimwidth(preg_replace('/@\[\d+:([^\]]+)\]/', '@$1', $msg->message), 0, 80, '…'),
                'created_at'   => $msg->created_at,
            ]);

        return response()->json($rows);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $user = $request->user();

        $projectId = $request->query('project_id');

        $users = User::where(function ($q) use ($projectId) {
            $q->where('type', 'admin')
              ->orWhere(function ($sq) use ($projectId) {
                  $sq->where('type', 'coordenador');
                  if ($projectId) {
                      $sq->whereHas('coordinatorProjects', fn($p) => $p->where('projects.id', (int) $projectId));
                  }
              });
        })
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

        return response()->json($users);
    }

    private function userCanAccessProject($user, Project $project): bool
    {
        if ($user->isCoordenador()) {
            return $project->coordinators()->where('users.id', $user->id)->exists();
        }
        if ($user->isCliente() && $user->customer_id) {
            return $project->customer_id === $user->customer_id;
        }
        return $project->consultants()->where('users.id', $user->id)->exists();
    }
}
