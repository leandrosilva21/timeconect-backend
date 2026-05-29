<?php

namespace App\Http\Controllers;

use App\Attachments\AttachableEntitiesRegistry;
use App\Attachments\AttachmentService;
use App\Attachments\Exceptions\AttachmentPermissionException;
use App\Attachments\Exceptions\EntityNotFoundException;
use App\Attachments\Exceptions\InvalidCategoryException;
use App\Attachments\Exceptions\InvalidMimeTypeException;
use App\Attachments\Exceptions\SizeExceededException;
use App\Attachments\Exceptions\UnknownEntityTypeException;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Camada HTTP única de anexos.
 *
 * Cada módulo legado (despesas, projetos, ...) continua com SEU controller próprio
 * até a FASE 11.2 migrar. Estas rotas são INDEPENDENTES — não interferem nada.
 *
 * Convenção de tradução de exceções → HTTP:
 *   UnknownEntityType / InvalidCategory / InvalidMime / SizeExceeded → 422
 *   EntityNotFound                                                  → 404
 *   AttachmentPermissionException                                   → 403
 *
 * Resposta JSON oficial:
 *   { id, entity_type, entity_id, category, original_name, mime_type, size_bytes,
 *     human_size, visibility, created_at, uploaded_by, signed_url? }
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id'   => 'required|integer|min:1',
            'category'    => 'nullable|string',
        ]);

        return $this->safe(function () use ($data, $request) {
            $items = $this->service->listFor(
                $data['entity_type'],
                (int) $data['entity_id'],
                $request->user(),
                $data['category'] ?? null,
            );
            return response()->json(['data' => $items->map(fn ($a) => $this->present($a))]);
        });
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->safe(function () use ($id, $request) {
            $att = $this->service->get($id, $request->user());
            return response()->json(['data' => $this->present($att)]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id'   => 'required|integer|min:1',
            'category'    => 'required|string',
            'file'        => 'required|file',
            'visibility'  => 'nullable|in:internal,customer,restricted',
            'metadata'    => 'nullable|array',
        ]);

        return $this->safe(function () use ($data, $request) {
            $att = $this->service->store($request->user(), [
                'entity_type' => $data['entity_type'],
                'entity_id'   => (int) $data['entity_id'],
                'category'    => $data['category'],
                'file'        => $request->file('file'),
                'visibility'  => $data['visibility'] ?? null,
                'metadata'    => $data['metadata'] ?? [],
            ], $request);
            return response()->json(['data' => $this->present($att)], 201);
        });
    }

    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        try {
            $att = $this->service->get($id, $request->user());
            $stream = $this->service->downloadStream($att, $request->user(), $request);
            $stream->headers->set('Content-Disposition', sprintf(
                'attachment; filename="%s"',
                addslashes($att->original_name),
            ));
            $stream->headers->set('Content-Type', $att->mime_type);
            return $stream;
        } catch (\Throwable $e) {
            return $this->translateException($e);
        }
    }

    public function signedUrl(Request $request, int $id): JsonResponse
    {
        $ttl = (int) $request->query('ttl', 300);
        $ttl = max(30, min($ttl, 3600)); // 30s a 1h
        return $this->safe(function () use ($id, $request, $ttl) {
            $att = $this->service->get($id, $request->user());
            $url = $this->service->url($att, $request->user(), $ttl, $request);
            return response()->json(['url' => $url, 'ttl_seconds' => $ttl]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->safe(function () use ($id, $request) {
            $att = $this->service->get($id, $request->user());
            $this->service->softDelete($att, $request->user(), $request);
            return response()->json(['data' => ['id' => $id, 'soft_deleted' => true]]);
        });
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->safe(function () use ($id, $request) {
            $att = Attachment::withTrashed()->findOrFail($id);
            $this->service->restore($att, $request->user(), $request);
            return response()->json(['data' => $this->present($att->fresh())]);
        });
    }

    /**
     * Rota assinada de download direto pelo storage_path (usada pelo LocalStorageProvider
     * ao gerar signed URL). NUNCA recebe input não-assinado em produção — middleware
     * 'signed' garante. Permissão revalida via service.
     */
    public function signedDownload(Request $request)
    {
        try {
            $keyB64 = (string) $request->query('key', '');
            $key = base64_decode($keyB64, true);
            if (!$key) abort(400, 'key inválida');

            // Localiza o attachment pelo storage_path (canonical).
            $att = Attachment::where('storage_path', $key)->whereNull('deleted_at')->firstOrFail();
            // Re-checa permissão mesmo com URL assinada: defesa em profundidade.
            $stream = $this->service->downloadStream($att, $request->user(), $request);
            $stream->headers->set('Content-Disposition', sprintf(
                'inline; filename="%s"',
                addslashes($att->original_name),
            ));
            $stream->headers->set('Content-Type', $att->mime_type);
            return $stream;
        } catch (\Throwable $e) {
            return $this->translateException($e);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function present(Attachment $a): array
    {
        return [
            'id'            => $a->id,
            'entity_type'   => $a->entity_type,
            'entity_id'     => (int) $a->entity_id,
            'category'      => $a->category,
            'original_name' => $a->original_name,
            'mime_type'     => $a->mime_type,
            'extension'     => $a->extension,
            'size_bytes'    => (int) $a->size_bytes,
            'human_size'    => $a->human_size,
            'visibility'    => $a->visibility,
            'uploaded_by'   => (int) $a->uploaded_by,
            'created_at'    => $a->created_at?->toIso8601String(),
            'deleted_at'    => $a->deleted_at?->toIso8601String(),
        ];
    }

    private function safe(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->translateException($e);
        }
    }

    private function translateException(\Throwable $e): JsonResponse
    {
        if ($e instanceof EntityNotFoundException) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
        if ($e instanceof AttachmentPermissionException) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
        if ($e instanceof UnknownEntityTypeException
            || $e instanceof InvalidCategoryException
            || $e instanceof InvalidMimeTypeException
            || $e instanceof SizeExceededException) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        // Inesperado — não vaza stack pro cliente; loga + 500.
        report($e);
        return response()->json(['message' => 'Erro interno ao processar anexo.'], 500);
    }
}
