<?php

namespace App\Http\Controllers;

use App\Attachments\AttachableEntitiesRegistry;
use App\Models\Attachment;
use App\Models\AttachmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FASE 11.5 — Observabilidade da camada global de anexos.
 *
 * 3 endpoints read-only consumidos pelo painel admin (/admin/attachments):
 *   GET /attachments/stats   — totais agregados + por entity_type / category
 *   GET /attachments/events  — timeline transversal paginada
 *   GET /attachments/health  — orphans, integrity_fail, falhas recentes
 *
 * Todos exigem admin. Queries são otimizadas pra usar os índices existentes em
 * (entity_type, entity_id), (entity_type, category) e (created_at).
 */
class AttachmentsAnalyticsController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        // KPIs gerais (live = não-deletados).
        $totalLive   = Attachment::query()->whereNull('deleted_at')->count();
        $totalAll    = Attachment::query()->withoutGlobalScopes()->withTrashed()->count();
        $totalBytes  = (int) Attachment::query()->whereNull('deleted_at')->sum('size_bytes');

        // Uploads últimas 24h / 7d / 30d.
        $now = now();
        $upload24h = Attachment::query()->where('created_at', '>=', $now->copy()->subDay())->count();
        $upload7d  = Attachment::query()->where('created_at', '>=', $now->copy()->subDays(7))->count();
        $upload30d = Attachment::query()->where('created_at', '>=', $now->copy()->subDays(30))->count();

        // Por entity_type (count + bytes). Index (entity_type, entity_id) cobre.
        $byEntity = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('entity_type')
            ->select('entity_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(size_bytes) as bytes'))
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => [
                'entity_type' => $r->entity_type,
                'count'       => (int) $r->count,
                'bytes'       => (int) $r->bytes,
                'human_size'  => $this->humanSize((int) $r->bytes),
                'in_registry' => in_array($r->entity_type, AttachableEntitiesRegistry::knownTypes(), true),
            ]);

        // Por category (top 15) — sinal de uso real das categorias do registry.
        $byCategory = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('entity_type', 'category')
            ->select('entity_type', 'category', DB::raw('COUNT(*) as count'))
            ->orderByDesc('count')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'entity_type' => $r->entity_type,
                'category'    => $r->category,
                'count'       => (int) $r->count,
            ]);

        // Por storage_provider (preparação pra multi-storage futuro).
        $byProvider = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('storage_provider')
            ->select('storage_provider', DB::raw('COUNT(*) as count'), DB::raw('SUM(size_bytes) as bytes'))
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->storage_provider,
                'count'    => (int) $r->count,
                'bytes'    => (int) $r->bytes,
            ]);

        // Por visibility.
        $byVisibility = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('visibility')
            ->select('visibility', DB::raw('COUNT(*) as count'))
            ->get()
            ->pluck('count', 'visibility');

        // Top 10 uploaders (rastreabilidade — quem mais grava docs).
        $topUploaders = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('uploaded_by')
            ->select('uploaded_by', DB::raw('COUNT(*) as count'))
            ->orderByDesc('count')
            ->limit(10)
            ->with('uploader:id,name,email')
            ->get()
            ->map(fn ($r) => [
                'user_id' => (int) $r->uploaded_by,
                'name'    => $r->uploader?->name,
                'count'   => (int) $r->count,
            ]);

        return response()->json([
            'data' => [
                'totals' => [
                    'live'       => $totalLive,
                    'all'        => $totalAll,
                    'deleted'    => $totalAll - $totalLive,
                    'bytes'      => $totalBytes,
                    'human_size' => $this->humanSize($totalBytes),
                ],
                'uploads' => [
                    'last_24h' => $upload24h,
                    'last_7d'  => $upload7d,
                    'last_30d' => $upload30d,
                ],
                'by_entity_type' => $byEntity,
                'by_category'    => $byCategory,
                'by_provider'    => $byProvider,
                'by_visibility'  => $byVisibility,
                'top_uploaders'  => $topUploaders,
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $data = $request->validate([
            'entity_type' => 'nullable|string',
            'event_type'  => 'nullable|string',
            'actor_id'    => 'nullable|integer',
            'attachment_id' => 'nullable|integer',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
            'page'        => 'nullable|integer|min:1',
            'per_page'    => 'nullable|integer|min:10|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 50);
        $page    = max(1, (int) ($data['page'] ?? 1));

        // Manual pagination (skip/take + count) — evita o `paginate()` do Laravel
        // que precisa de URL::current pra gerar links e quebra fora de contexto
        // HTTP (ex.: tests via Request::create).
        $q = AttachmentEvent::query()
            ->with([
                'attachment:id,entity_type,entity_id,category,original_name,storage_path,visibility,uploaded_by',
                'actor:id,name,email,type',
            ])
            ->orderByDesc('created_at');

        if (!empty($data['event_type'])) {
            $q->where('event_type', $data['event_type']);
        }
        if (!empty($data['actor_id'])) {
            $q->where('actor_user_id', (int) $data['actor_id']);
        }
        if (!empty($data['attachment_id'])) {
            $q->where('attachment_id', (int) $data['attachment_id']);
        }
        if (!empty($data['entity_type'])) {
            $q->whereHas('attachment', fn ($a) => $a->where('entity_type', $data['entity_type']));
        }
        if (!empty($data['from'])) {
            $q->where('created_at', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $q->where('created_at', '<=', $data['to']);
        }

        $total = (clone $q)->count();
        $items = $q->skip(($page - 1) * $perPage)->take($perPage)->get();
        $lastPage = max(1, (int) ceil($total / max($perPage, 1)));

        return response()->json([
            'data' => $items->map(fn ($e) => [
                'id'         => $e->id,
                'event_type' => $e->event_type,
                'created_at' => $e->created_at?->toIso8601String(),
                'metadata'   => $e->metadata,
                'ip'         => $e->ip,
                'actor'      => $e->actor ? [
                    'id'    => $e->actor->id,
                    'name'  => $e->actor->name,
                    'type'  => $e->actor->type,
                    'email' => $e->actor->email,
                ] : null,
                'attachment' => $e->attachment ? [
                    'id'            => $e->attachment->id,
                    'entity_type'   => $e->attachment->entity_type,
                    'entity_id'     => $e->attachment->entity_id,
                    'category'      => $e->attachment->category,
                    'original_name' => $e->attachment->original_name,
                    'visibility'    => $e->attachment->visibility,
                ] : null,
            ])->values(),
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => $lastPage,
                'has_next'  => $page < $lastPage,
            ],
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        // Falhas de integridade — últimas 24h e 7 dias (do job).
        $integrityFail24h = AttachmentEvent::query()
            ->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $integrityFail7d = AttachmentEvent::query()
            ->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // Últimas 20 falhas pra inspeção (com attachment + razão).
        $recentFailures = AttachmentEvent::query()
            ->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)
            ->with('attachment:id,entity_type,entity_id,category,storage_path,storage_provider')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($e) => [
                'created_at' => $e->created_at?->toIso8601String(),
                'attachment_id' => $e->attachment_id,
                'entity'        => $e->attachment ? "{$e->attachment->entity_type}#{$e->attachment->entity_id}" : null,
                'category'      => $e->attachment?->category,
                'storage_path'  => $e->attachment?->storage_path,
                'failures'      => $e->metadata['failures'] ?? [],
            ]);

        // MIME violations e permission denied — sinais de uso indevido.
        $mimeViolations24h = AttachmentEvent::query()
            ->where('event_type', AttachmentEvent::TYPE_MIME_VIOLATION)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $permDenied24h = AttachmentEvent::query()
            ->where('event_type', AttachmentEvent::TYPE_PERMISSION_DENIED)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        // Soft-deleted recentes (cleanup futuro / restore).
        $softDeleted24h = Attachment::query()
            ->onlyTrashed()
            ->where('deleted_at', '>=', now()->subDay())
            ->count();

        // Sinal de saúde global.
        $healthy = $integrityFail7d === 0 && $integrityFail24h === 0;

        return response()->json([
            'data' => [
                'healthy'              => $healthy,
                'integrity_fail_24h'   => $integrityFail24h,
                'integrity_fail_7d'    => $integrityFail7d,
                'mime_violations_24h'  => $mimeViolations24h,
                'permission_denied_24h'=> $permDenied24h,
                'soft_deleted_24h'     => $softDeleted24h,
                'recent_failures'      => $recentFailures,
            ],
        ]);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }
}
