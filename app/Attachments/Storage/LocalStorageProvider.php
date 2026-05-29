<?php

namespace App\Attachments\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Storage provider local (filesystem do servidor).
 *
 * Convenção: TODOS os anexos novos do AttachmentService vão pro disco 'local'
 * (privado, em storage/app/). Visibilidade é resolvida ao GERAR a URL — não ao
 * gravar. Isso evita o problema atual de "metade no public/ + metade no app/"
 * que mistura semântica de armazenamento com semântica de permissão.
 *
 * URLs: rotas internas assinadas via Laravel signed URL (TTL configurável).
 * Quando S3/R2 entrar, o LocalStorageProvider continua atendendo os arquivos
 * legados — co-existência por design.
 */
class LocalStorageProvider implements StorageProvider
{
    /**
     * Disco do Laravel usado por NOVOS uploads. O `local` é privado (não tem
     * symlink em public/) — segurança por padrão.
     */
    private const NEW_UPLOADS_DISK = 'local';

    public function name(): string
    {
        return 'local';
    }

    public function put(string $key, string $content, ?string $mimeType = null): void
    {
        $disk = $this->resolveDiskForKey($key);
        Storage::disk($disk)->put($key, $content, [
            'visibility' => $disk === 'public' ? 'public' : 'private',
        ]);
    }

    public function putUploaded(string $key, UploadedFile $file): void
    {
        $disk = $this->resolveDiskForKey($key);
        // putFileAs: stream-friendly, não carrega o blob em memória.
        Storage::disk($disk)->putFileAs(
            dirname($key) === '.' ? '' : dirname($key),
            $file,
            basename($key),
            $disk === 'public' ? 'public' : 'private',
        );
    }

    public function get(string $key): string
    {
        $disk = $this->resolveDiskForKey($key);
        if (!Storage::disk($disk)->exists($key)) {
            throw new RuntimeException("LocalStorageProvider: arquivo não existe — disk={$disk} key={$key}");
        }
        return Storage::disk($disk)->get($key);
    }

    public function delete(string $key): void
    {
        $disk = $this->resolveDiskForKey($key);
        Storage::disk($disk)->delete($key);
    }

    public function exists(string $key): bool
    {
        return Storage::disk($this->resolveDiskForKey($key))->exists($key);
    }

    /**
     * URL assinada: rota interna /api/v1/attachments/by-path/{...} protegida por
     * signed middleware. TTL em segundos. AttachmentController.url() é quem expõe
     * isso publicamente — aqui só gera o link.
     *
     * NOTA: a rota é criada na FASE 11.1 (rotas REST genéricas). Até lá, esta
     * função devolve um marker `signed://` que o AttachmentController vai resolver.
     */
    public function url(string $key, int $ttlSeconds = 300): string
    {
        // Geração defensiva: se a rota ainda não existe no app (deploy parcial),
        // não estoura — devolve o marker e logamos o problema upstream.
        if (\Route::has('attachments.signed-download')) {
            return URL::temporarySignedRoute(
                'attachments.signed-download',
                now()->addSeconds($ttlSeconds),
                ['key' => base64_encode($key)],
            );
        }
        return 'signed://' . base64_encode($key);
    }

    public function checksum(string $key): string
    {
        $disk = $this->resolveDiskForKey($key);
        if (!Storage::disk($disk)->exists($key)) {
            throw new RuntimeException("LocalStorageProvider::checksum — arquivo não existe — disk={$disk} key={$key}");
        }
        // Calcula direto do path absoluto pra evitar carregar conteúdo em memória.
        $absPath = Storage::disk($disk)->path($key);
        $hash = hash_file('sha256', $absPath);
        if ($hash === false) {
            throw new RuntimeException("LocalStorageProvider::checksum — falha ao calcular sha256 — key={$key}");
        }
        return $hash;
    }

    public function size(string $key): int
    {
        $disk = $this->resolveDiskForKey($key);
        if (!Storage::disk($disk)->exists($key)) {
            throw new RuntimeException("LocalStorageProvider::size — arquivo não existe — key={$key}");
        }
        return (int) Storage::disk($disk)->size($key);
    }

    public function downloadStream(string $key): StreamedResponse
    {
        $disk = $this->resolveDiskForKey($key);
        return Storage::disk($disk)->download($key);
    }

    /**
     * Decide o disco do Laravel a partir da key.
     *
     * Convenção:
     *  - Keys de novos uploads (FASE 11) começam com "attachments/"   → disco 'local' (privado)
     *  - Keys legadas começam com "public/" ou paths antigos sem prefixo → disco 'public'
     *  - Keys legadas que estão em storage/app/ (privado) usam 'local'
     *
     * Importante: storage_path é OPACO; o provider sabe interpretar. Caller (service)
     * só lê/grava — não inspeciona path.
     */
    private function resolveDiskForKey(string $key): string
    {
        if (str_starts_with($key, 'attachments/')) {
            return self::NEW_UPLOADS_DISK;
        }
        if (str_starts_with($key, 'public/')) {
            // path antigo já com prefixo public/ explícito
            return 'public';
        }
        // Heurística pros legados em disco PUBLIC (com symlink em public/storage):
        //   receipts/, timesheets/, message-attachments/, contract-message-attachments/,
        //   req-message-attachments/, stage-attachments/, fechamento-notas/, profile_photos/.
        // CUIDADO: 'projects/' e 'contracts/' (anexos de Project/Contract) vão pro disco
        // LOCAL privado — o legado usa $file->store() sem 3º arg = local. Por isso NÃO
        // estão no array abaixo. Mesmo raciocínio pra hour_contributions/ e fechamentos/.
        $publicPrefixes = [
            'receipts/', 'timesheets/',
            'message-attachments/', 'contract-message-attachments/', 'req-message-attachments/',
            'stage-attachments/', 'fechamento-notas/', 'profile_photos/',
        ];
        foreach ($publicPrefixes as $p) {
            if (str_starts_with($key, $p)) return 'public';
        }
        // Legado privado: projects/, contracts/, hour_contributions/, fechamentos/, etc.
        return 'local';
    }
}
