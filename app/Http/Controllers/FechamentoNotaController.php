<?php

namespace App\Http\Controllers;

use App\Models\FechamentoNota;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Upload / download / aceite-recusa das notas fiscais (NFS-e e Nota de débito)
 * de entidades PJ no fechamento. Serve tanto Fechamento Consultor (User PJ) quanto
 * Fechamento Parceiro (Partner PJ). Aceite/recusa é por documento e só admin decide.
 */
class FechamentoNotaController extends Controller
{
    private const DISK = 'public';

    /** [model, ehPj] a partir de consultor|parceiro. */
    private function entity(string $type, int $id): array
    {
        if ($type === 'consultor') {
            $u = User::findOrFail($id);
            // Só PJ AVULSO (sem parceiro) anexa nota como consultor. Consultor de parceiro
            // PJ não anexa individualmente — a nota é do parceiro (via o admin do parceiro).
            return [$u, ($u->contract_type === 'pj' && $u->partner_id === null)];
        }
        if ($type === 'parceiro') {
            $p = Partner::findOrFail($id);
            return [$p, ($p->contract_type === 'pj')];
        }
        abort(404, 'Tipo de entidade inválido');
    }

    private function canUpload($model, string $type): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if ($user->isAdmin()) return true;
        if ($type === 'consultor') return (int) $user->id === (int) $model->id;
        if ($type === 'parceiro')  return (int) ($user->partner_id ?? 0) === (int) $model->id;
        return false;
    }

    /** POST .../fechamento/notas/{type}/{id}/{yearMonth} — sobe NFS-e ou Nota de débito. */
    public function upload(Request $request, string $type, int $id, string $yearMonth): JsonResponse
    {
        [$model, $isPj] = $this->entity($type, $id);
        if (!$isPj) {
            return response()->json(['error' => 'Notas fiscais só se aplicam a entidades PJ'], 422);
        }
        if (!$this->canUpload($model, $type)) {
            return response()->json(['error' => 'Sem permissão para enviar a nota'], 403);
        }

        $validated = $request->validate([
            'tipo' => 'required|in:nfse,nota_debito',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'tipo.in'     => 'Tipo de documento inválido (use nfse ou nota_debito)',
            'file.mimes'  => 'Anexo deve ser PDF, JPG ou PNG',
            'file.max'    => 'Anexo não pode exceder 5MB',
        ]);

        $tipo = $validated['tipo'];
        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $filename = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $original);
        $path = $file->storeAs("fechamento-notas/{$yearMonth}/{$type}-{$id}", $filename, self::DISK);

        $nota = FechamentoNota::firstOrNew([
            'notable_type' => get_class($model),
            'notable_id'   => $model->id,
            'year_month'   => $yearMonth,
        ]);

        // Apaga o arquivo anterior do mesmo tipo, se houver.
        $oldPath = $nota->{$tipo . '_path'};
        if ($oldPath && Storage::disk(self::DISK)->exists($oldPath)) {
            Storage::disk(self::DISK)->delete($oldPath);
            // FASE 11.2 — soft-delete attachment paralelo do anterior (mesma categoria).
            $this->dualSoftDeleteFechamentoNotaByCategory($nota, $tipo);
        }

        // Novo upload reseta o status do documento para pendente.
        $nota->{$tipo . '_path'}          = $path;
        $nota->{$tipo . '_original_name'} = $original;
        $nota->{$tipo . '_status'}        = FechamentoNota::STATUS_PENDING;
        $nota->{$tipo . '_reject_reason'} = null;
        $nota->{$tipo . '_decided_by'}    = null;
        $nota->{$tipo . '_decided_at'}    = null;
        $nota->save();

        // FASE 11.2 — dual-write do novo upload (categoria = $tipo: nfse | nota_debito).
        $this->dualWriteFechamentoNota($nota, $tipo, $file, $path);

        return response()->json(['ok' => true, 'notas' => $nota->toRowPayload()]);
    }

    /** GET .../fechamento/notas/{type}/{id}/{yearMonth} — estado atual das notas (dono ou admin). */
    public function show(string $type, int $id, string $yearMonth): JsonResponse
    {
        [$model, $isPj] = $this->entity($type, $id);
        if (!$isPj) {
            return response()->json(['notas' => null]);
        }
        if (!$this->canUpload($model, $type)) {
            return response()->json(['error' => 'Sem permissão'], 403);
        }

        $nota = FechamentoNota::where('notable_type', get_class($model))
            ->where('notable_id', $model->id)
            ->where('year_month', $yearMonth)
            ->first();

        return response()->json(['notas' => $nota ? $nota->toRowPayload() : FechamentoNota::emptyRowPayload()]);
    }

    /** GET .../fechamento/notas/{type}/{id}/{yearMonth}/{tipo}/download */
    public function download(string $type, int $id, string $yearMonth, string $tipo)
    {
        if (!in_array($tipo, FechamentoNota::TIPOS, true)) {
            abort(404);
        }
        [$model] = $this->entity($type, $id);

        $nota = FechamentoNota::where('notable_type', get_class($model))
            ->where('notable_id', $model->id)
            ->where('year_month', $yearMonth)
            ->first();

        $path = $nota?->{$tipo . '_path'};
        if (!$path || !Storage::disk(self::DISK)->exists($path)) {
            abort(404, 'Anexo não encontrado');
        }

        return Storage::disk(self::DISK)->download($path, $nota->{$tipo . '_original_name'} ?? basename($path));
    }

    /** POST .../fechamento/notas/{type}/{id}/{yearMonth}/{tipo}/decisao — só admin. */
    public function decisao(Request $request, string $type, int $id, string $yearMonth, string $tipo): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'Apenas administradores podem aceitar/recusar notas'], 403);
        }
        if (!in_array($tipo, FechamentoNota::TIPOS, true)) {
            return response()->json(['error' => 'Tipo de documento inválido'], 422);
        }

        $validated = $request->validate([
            'decisao' => 'required|in:accepted,rejected',
            'motivo'  => 'nullable|string|max:1000',
        ]);
        if ($validated['decisao'] === FechamentoNota::STATUS_REJECTED && empty(trim($validated['motivo'] ?? ''))) {
            return response()->json(['error' => 'Motivo é obrigatório ao recusar a nota'], 422);
        }

        [$model] = $this->entity($type, $id);

        $nota = FechamentoNota::where('notable_type', get_class($model))
            ->where('notable_id', $model->id)
            ->where('year_month', $yearMonth)
            ->first();

        if (!$nota || empty($nota->{$tipo . '_path'})) {
            return response()->json(['error' => 'Não há nota enviada para decidir'], 422);
        }

        $nota->{$tipo . '_status'}        = $validated['decisao'];
        $nota->{$tipo . '_reject_reason'} = $validated['decisao'] === FechamentoNota::STATUS_REJECTED
            ? trim($validated['motivo'])
            : null;
        $nota->{$tipo . '_decided_by'}    = $user->id;
        $nota->{$tipo . '_decided_at'}    = now();
        $nota->save();

        return response()->json(['ok' => true, 'notas' => $nota->toRowPayload()]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FASE 11.2 — Helpers de dual-write da nota fiscal
    //
    // A FechamentoNota é polimórfica (notable_type=User|Partner). No registry o
    // entity_type é 'FECHAMENTO_NOTA' e entity_id = fechamento_notas.id (a row
    // de notas, não o user/partner). category distingue os 2 documentos da row:
    // nfse | nota_debito.
    // ──────────────────────────────────────────────────────────────────────────

    private function dualWriteFechamentoNota(FechamentoNota $nota, string $tipo, \Illuminate\Http\UploadedFile $file, string $path): void
    {
        try {
            $actor = Auth::user();
            if (!$actor) return;
            app(\App\Attachments\AttachmentService::class)->registerExisting($actor, [
                'entity_type'   => 'FECHAMENTO_NOTA',
                'entity_id'     => $nota->id,
                'category'      => $tipo,  // 'nfse' | 'nota_debito'
                'storage_path'  => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType() ?: 'application/pdf',
                'metadata'      => [
                    'notable_type' => $nota->notable_type,
                    'notable_id'   => $nota->notable_id,
                    'year_month'   => $nota->year_month,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('FASE11 dual-write FECHAMENTO_NOTA falhou (não-fatal)', [
                'fechamento_nota_id' => $nota->id,
                'tipo'               => $tipo,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    private function dualSoftDeleteFechamentoNotaByCategory(FechamentoNota $nota, string $tipo): void
    {
        try {
            \App\Models\Attachment::query()
                ->forEntity('FECHAMENTO_NOTA', $nota->id)
                ->ofCategory($tipo)
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete()); // SoftDeletes
        } catch (\Throwable $e) {
            \Log::warning('FASE11 dual-delete FECHAMENTO_NOTA falhou (não-fatal)', [
                'fechamento_nota_id' => $nota->id,
                'tipo'               => $tipo,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
