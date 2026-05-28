<?php

namespace App\Attachments;

use App\Attachments\Exceptions\AttachmentPermissionException;
use App\Attachments\Exceptions\SizeExceededException;
use App\Attachments\Storage\StorageProvider;
use App\Models\Attachment;
use App\Models\AttachmentEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Engine global de anexos.
 *
 * Toda operação:
 *  1) Valida via AttachableEntitiesRegistry (existência da entidade, permissão, mime,
 *     extensão, tamanho, categoria).
 *  2) Executa via StorageProvider (sem tocar Storage:: do Laravel direto).
 *  3) Persiste em Attachment.
 *  4) Audita em AttachmentEvent.
 *
 * NÃO conhece nenhum módulo de negócio. Toda dependência de entidade-dona é via
 * registry — registry resolve $entityType → Model::find(), nunca a service direto.
 *
 * Anti-N+1:
 *  - `listFor` / `aggregateLoader` carregam em UMA query (whereIn).
 *  - Atomic counters / fanout futuro deve passar pelo aggregateLoader também.
 */
class AttachmentService
{
    public function __construct(
        private readonly StorageProvider $storage,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // STORE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Upload completo: valida tudo, dedup por checksum, grava no storage, persiste
     * e audita. Idempotente por checksum dentro do mesmo (entity_type, entity_id, category).
     *
     * @param array{
     *   entity_type: string,
     *   entity_id: int,
     *   category: string,
     *   file: UploadedFile,
     *   visibility?: 'internal'|'customer'|'restricted',
     *   metadata?: array,
     * } $input
     */
    public function store(User $actor, array $input, ?Request $request = null): Attachment
    {
        $entityType = $input['entity_type'];
        $entityId   = (int) $input['entity_id'];
        $category   = $input['category'];
        $file       = $input['file'];

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \InvalidArgumentException('UploadedFile inválido.');
        }

        // 1. Valida entidade + categoria + mime + extensão + tamanho via registry.
        AttachableEntitiesRegistry::assertCategory($entityType, $category);
        $entity   = AttachableEntitiesRegistry::resolve($entityType, $entityId);
        $maxSize  = AttachableEntitiesRegistry::maxSizeMb($entityType);

        $sizeBytes = (int) $file->getSize();
        if ($sizeBytes > ($maxSize * 1048576)) {
            $this->logEvent(null, AttachmentEvent::TYPE_SIZE_EXCEEDED, $actor, $request, [
                'entity_type' => $entityType, 'entity_id' => $entityId, 'size_bytes' => $sizeBytes, 'max_mb' => $maxSize,
            ]);
            throw SizeExceededException::for($entityType, $sizeBytes, $maxSize);
        }

        $mime = (string) ($file->getMimeType() ?? 'application/octet-stream');
        $ext  = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        try {
            AttachableEntitiesRegistry::assertMime($entityType, $mime);
            AttachableEntitiesRegistry::assertExtension($entityType, $ext);
        } catch (\Throwable $e) {
            $this->logEvent(null, AttachmentEvent::TYPE_MIME_VIOLATION, $actor, $request, [
                'entity_type' => $entityType, 'entity_id' => $entityId, 'mime' => $mime, 'extension' => $ext, 'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // 2. Permissão de upload.
        if (!AttachableEntitiesRegistry::can($actor, $entityType, $entity, 'upload')) {
            $this->logEvent(null, AttachmentEvent::TYPE_PERMISSION_DENIED, $actor, $request, [
                'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'upload',
            ]);
            throw AttachmentPermissionException::for('upload', $entityType, $entityId);
        }

        // 3. Checksum DO arquivo antes de salvar (lê do upload, não do storage).
        $checksum = hash_file('sha256', $file->getRealPath()) ?: '';
        if ($checksum === '') {
            throw new \RuntimeException('Falha ao calcular checksum do upload.');
        }

        // 4. Dedup: se já existe attachment vivo com mesmo (entity, category, checksum),
        //    NÃO duplica — devolve o existente. Auditoria registra de qualquer forma.
        $existing = Attachment::query()
            ->forEntity($entityType, $entityId)
            ->ofCategory($category)
            ->where('checksum', $checksum)
            ->whereNull('deleted_at')
            ->first();
        if ($existing !== null) {
            $this->logEvent($existing, AttachmentEvent::TYPE_UPLOADED, $actor, $request, [
                'dedup' => true, 'reason' => 'checksum_match',
            ]);
            return $existing;
        }

        // 5. Sanitiza nome + monta storage key.
        $originalName = $this->safeName($file->getClientOriginalName() ?: ('upload.' . $ext));
        $fileName     = sprintf('%s_%s.%s', (string) Str::uuid(), substr($checksum, 0, 8), $ext);
        $storageKey   = sprintf(
            'attachments/%s/%d/%s',
            strtolower($entityType),
            $entityId,
            $fileName,
        );

        // 6. Persiste em transação: storage primeiro (rollback se DB falhar), DB depois.
        $this->storage->putUploaded($storageKey, $file);

        try {
            $att = DB::transaction(function () use ($actor, $entityType, $entityId, $category, $input, $originalName, $fileName, $mime, $ext, $sizeBytes, $checksum, $storageKey, $entity) {
                $visibility = $input['visibility']
                    ?? AttachableEntitiesRegistry::defaultVisibility($entityType);

                // company_id: hoje sem multi-tenant, fica null. Quando a coluna existir
                // nos models, este é o ponto único de propagação.
                $companyId = property_exists($entity, 'company_id') ? ($entity->company_id ?? null) : null;

                return Attachment::create([
                    'company_id'       => $companyId,
                    'entity_type'      => $entityType,
                    'entity_id'        => $entityId,
                    'category'         => $category,
                    'original_name'    => $originalName,
                    'file_name'        => $fileName,
                    'mime_type'        => $mime,
                    'extension'        => $ext,
                    'size_bytes'       => $sizeBytes,
                    'checksum'         => $checksum,
                    'storage_provider' => $this->storage->name(),
                    'storage_path'     => $storageKey,
                    'visibility'       => $visibility,
                    'uploaded_by'      => $actor->id,
                    'metadata'         => $input['metadata'] ?? [],
                ]);
            });
        } catch (\Throwable $e) {
            // Rollback do storage — não deixa arquivo órfão.
            try { $this->storage->delete($storageKey); } catch (\Throwable $_) {}
            throw $e;
        }

        $this->logEvent($att, AttachmentEvent::TYPE_UPLOADED, $actor, $request, [
            'original_name' => $originalName, 'size_bytes' => $sizeBytes, 'mime' => $mime,
        ]);

        return $att;
    }

    /**
     * Registra um arquivo JÁ EXISTENTE no storage como Attachment, SEM copiar nada.
     *
     * Caminho oficial pra dual-write durante a migração FASE 11.2: módulo legado
     * grava no path antigo (ex.: profile_photos/foo.png), depois chama isso pra
     * registrar uma row em `attachments` apontando pro MESMO arquivo. Respeita
     * a decisão "manter where they are" — zero duplicação física.
     *
     * Também usado pelo backfill (FASE 11.3) varrendo coluna legada.
     *
     * Dedup por checksum dentro de (entity, category) — mesmo comportamento do store().
     */
    public function registerExisting(User $actor, array $input): Attachment
    {
        $entityType  = $input['entity_type'];
        $entityId    = (int) $input['entity_id'];
        $category    = $input['category'];
        $storagePath = (string) $input['storage_path'];
        $originalName = $input['original_name'] ?? basename($storagePath);

        // 1. Valida entidade + categoria (mesma rota do store).
        AttachableEntitiesRegistry::assertCategory($entityType, $category);
        $entity = AttachableEntitiesRegistry::resolve($entityType, $entityId);

        // 2. Arquivo precisa existir no provider (defesa: não criar registro órfão).
        if (!$this->storage->exists($storagePath)) {
            throw new \RuntimeException("registerExisting: arquivo não existe no storage — path={$storagePath}");
        }

        // 3. Checksum DO arquivo no storage (não confia em input).
        $checksum = $this->storage->checksum($storagePath);
        $sizeBytes = $this->storage->size($storagePath);

        // 4. MIME via finfo no arquivo do storage. Sem upload, finfo no path absoluto.
        $mime = $input['mime_type'] ?? 'application/octet-stream';
        $ext  = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));

        // 5. Dedup.
        $existing = Attachment::query()
            ->forEntity($entityType, $entityId)
            ->ofCategory($category)
            ->where('checksum', $checksum)
            ->whereNull('deleted_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $fileName = basename($storagePath);

        $companyId = property_exists($entity, 'company_id') ? ($entity->company_id ?? null) : null;
        $visibility = $input['visibility'] ?? AttachableEntitiesRegistry::defaultVisibility($entityType);

        $att = Attachment::create([
            'company_id'       => $companyId,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'category'         => $category,
            'original_name'    => $this->safeName((string) $originalName),
            'file_name'        => $fileName,
            'mime_type'        => $mime,
            'extension'        => $ext,
            'size_bytes'       => $sizeBytes,
            'checksum'         => $checksum,
            'storage_provider' => $this->storage->name(),
            'storage_path'     => $storagePath,
            'visibility'       => $visibility,
            'uploaded_by'      => $actor->id,
            'metadata'         => array_merge([
                'registered_existing' => true,  // marker pra distinguir de upload novo
            ], $input['metadata'] ?? []),
        ]);

        $this->logEvent($att, AttachmentEvent::TYPE_UPLOADED, $actor, null, [
            'source' => 'register_existing',
            'storage_path' => $storagePath,
        ]);

        return $att;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // READ
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Busca anexo respeitando permissão. Lança AttachmentPermissionException se negar.
     */
    public function get(int $id, User $actor): Attachment
    {
        $att = Attachment::findOrFail($id);
        $this->ensureCanAccess($att, $actor, 'view');
        return $att;
    }

    /**
     * Lista anexos de UMA entidade (escopo da tela: card / modal / aba).
     * Filtra por permissão (oculta restricted que o user não tem acesso).
     */
    public function listFor(string $entityType, int $entityId, User $actor, ?string $category = null): Collection
    {
        $entity = AttachableEntitiesRegistry::resolve($entityType, $entityId);
        if (!AttachableEntitiesRegistry::can($actor, $entityType, $entity, 'view')) {
            throw AttachmentPermissionException::for('view', $entityType, $entityId);
        }
        $q = Attachment::query()->forEntity($entityType, $entityId);
        if ($category !== null) {
            $q->ofCategory($category);
        }
        return $q->orderBy('created_at')->get();
    }

    /**
     * Anti-N+1: carrega anexos de N entidades do MESMO entity_type em UMA query.
     * Retorna Collection agrupada por entity_id.
     *
     * Caller típico: listagem do kanban / gestão de projetos / fechamento, que
     * precisa colar attachments[] em cada row sem disparar 1 query por row.
     */
    public function aggregateLoader(string $entityType, array $entityIds, ?string $category = null): Collection
    {
        if (empty($entityIds)) return collect();
        $q = Attachment::query()->forEntities($entityType, $entityIds);
        if ($category !== null) $q->ofCategory($category);
        return $q->orderBy('created_at')->get()->groupBy('entity_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DOWNLOAD / URL
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Gera signed URL com TTL. Audita.
     */
    public function url(Attachment $att, User $actor, int $ttlSeconds = 300, ?Request $request = null): string
    {
        $this->ensureCanAccess($att, $actor, 'view');
        $url = $this->storage->url($att->storage_path, $ttlSeconds);
        $this->logEvent($att, AttachmentEvent::TYPE_URL_SIGNED, $actor, $request, ['ttl_seconds' => $ttlSeconds]);
        return $url;
    }

    /**
     * Stream pra download. AttachmentController seta os headers de Content-Disposition.
     */
    public function downloadStream(Attachment $att, User $actor, ?Request $request = null): StreamedResponse
    {
        $this->ensureCanAccess($att, $actor, 'view');
        $stream = $this->storage->downloadStream($att->storage_path);
        $this->logEvent($att, AttachmentEvent::TYPE_DOWNLOADED, $actor, $request);
        return $stream;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SOFT DELETE / RESTORE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Soft delete: marca deleted_at, NÃO apaga storage. Documento preservado.
     */
    public function softDelete(Attachment $att, User $actor, ?Request $request = null): void
    {
        $this->ensureCanAccess($att, $actor, 'delete');
        $att->delete(); // SoftDeletes — só seta deleted_at
        $this->logEvent($att, AttachmentEvent::TYPE_SOFT_DELETED, $actor, $request);
    }

    /**
     * Restore: reverte soft delete. Requer permissão 'restore'.
     */
    public function restore(Attachment $att, User $actor, ?Request $request = null): void
    {
        $this->ensureCanAccess($att, $actor, 'restore');
        $att->restore();
        $this->logEvent($att, AttachmentEvent::TYPE_RESTORED, $actor, $request);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // INTEGRITY
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validação completa de um anexo:
     *  - entity_type conhecido?
     *  - entidade-dona existe?
     *  - arquivo físico existe?
     *  - checksum bate?
     *  - storage_provider conhecido?
     *
     * Retorna IntegrityReport. Falhas registram event_type='integrity_fail'.
     */
    public function integrityCheck(Attachment $att): IntegrityReport
    {
        $report = new IntegrityReport($att->id);

        try {
            $entity = AttachableEntitiesRegistry::resolve($att->entity_type, $att->entity_id);
            if ($entity === null) $report->add('entity_missing');
        } catch (\Throwable $e) {
            $report->add('entity_missing', $e->getMessage());
        }

        if ($this->storage->name() !== $att->storage_provider) {
            $report->add('storage_provider_mismatch', "provider DB={$att->storage_provider} vs service=" . $this->storage->name());
        }

        if (!$this->storage->exists($att->storage_path)) {
            $report->add('file_missing', "path={$att->storage_path}");
        } else {
            try {
                $diskChecksum = $this->storage->checksum($att->storage_path);
                if ($diskChecksum !== $att->checksum) {
                    $report->add('checksum_mismatch', "db={$att->checksum} disk={$diskChecksum}");
                }
            } catch (\Throwable $e) {
                $report->add('checksum_error', $e->getMessage());
            }
        }

        if ($report->hasFailures()) {
            $this->logEvent($att, AttachmentEvent::TYPE_INTEGRITY_FAIL, null, null, [
                'failures' => $report->failures(),
            ]);
        }

        return $report;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // INTERNALS
    // ──────────────────────────────────────────────────────────────────────────

    private function ensureCanAccess(Attachment $att, User $actor, string $action): void
    {
        // Visibility=restricted: uploader sempre tem acesso; demais via registry.
        if ($att->visibility === 'restricted' && (int) $att->uploaded_by === (int) $actor->id) {
            return;
        }

        try {
            $entity = AttachableEntitiesRegistry::resolve($att->entity_type, $att->entity_id);
        } catch (\Throwable $e) {
            // Entidade não existe mais — só admin pode acessar (pra restore/inspect).
            if (!$actor->isAdmin()) {
                throw AttachmentPermissionException::for($action, $att->entity_type, $att->entity_id);
            }
            return;
        }

        if (!AttachableEntitiesRegistry::can($actor, $att->entity_type, $entity, $action)) {
            $this->logEvent($att, AttachmentEvent::TYPE_PERMISSION_DENIED, $actor, null, ['action' => $action]);
            throw AttachmentPermissionException::for($action, $att->entity_type, $att->entity_id);
        }
    }

    private function safeName(string $name): string
    {
        // Sanitização branda — preserva nome amigável (com acento, espaço) mas remove
        // path-traversal, controle e nul. O file_name de storage é UUID, então não
        // depende disso pra segurança — isso é só pra exibição.
        $name = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = trim($name);
        return $name === '' ? 'arquivo' : mb_substr($name, 0, 255);
    }

    private function logEvent(?Attachment $att, string $type, ?User $actor, ?Request $request, array $metadata = []): void
    {
        // Se não há attachment_id (ex.: falha de validação antes do persist), não logamos
        // por enquanto — o evento seria órfão. Considerar tabela de "attempt_events" futuro.
        if ($att === null) return;
        try {
            AttachmentEvent::create([
                'attachment_id' => $att->id,
                'event_type'    => $type,
                'actor_user_id' => $actor?->id,
                'ip'            => $request?->ip(),
                'user_agent'    => $request?->userAgent() ? mb_substr((string) $request->userAgent(), 0, 512) : null,
                'metadata'      => $metadata,
            ]);
        } catch (\Throwable $e) {
            // Audit log nunca pode quebrar a operação principal.
            report($e);
        }
    }
}
