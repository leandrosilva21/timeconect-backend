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

        // Prazo de envio: notas não são aceitas após o dia 15 do mês SEGUINTE à competência
        // (ex.: competência 05/2026 → prazo 15/06/2026), salvo liberação do administrativo
        // para este notable+mês — admin sempre pode enviar.
        $deadline = \Carbon\Carbon::parse($yearMonth . '-01')->addMonthNoOverflow()->day(15)->endOfDay();
        if (now()->greaterThan($deadline) && !auth()->user()->isAdmin()) {
            $existing = FechamentoNota::where('notable_type', get_class($model))
                ->where('notable_id', $model->id)
                ->where('year_month', $yearMonth)
                ->first();
            if (!$existing || !$existing->upload_liberado) {
                return response()->json([
                    'error' => 'Prazo de envio encerrado (dia 15). Fale com o setor financeiro ou solicite liberação ao administrativo.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'tipo'  => 'required|in:nfse,nota_debito',
            'file'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'valor' => 'required|numeric|gt:0',
        ], [
            'tipo.in'       => 'Tipo de documento inválido (use nfse ou nota_debito)',
            'file.mimes'    => 'Anexo deve ser PDF, JPG ou PNG',
            'file.max'      => 'Anexo não pode exceder 5MB',
            'valor.required'=> 'Informe o valor da nota',
            'valor.numeric' => 'Valor da nota inválido',
            'valor.gt'      => 'Valor da nota deve ser maior que zero',
        ]);

        // O valor declarado deve bater com o recebimento do fechamento do mês.
        $valor = round((float) $validated['valor'], 2);
        $recebimentoEsperado = $this->recebimentoEsperado($type, (int) $id, $yearMonth);
        if ($recebimentoEsperado !== null && abs($valor - $recebimentoEsperado) > 0.01) {
            return response()->json([
                'error' => sprintf(
                    'Valor informado (R$ %s) diferente do recebimento do fechamento (R$ %s).',
                    number_format($valor, 2, ',', '.'),
                    number_format($recebimentoEsperado, 2, ',', '.'),
                ),
            ], 422);
        }

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

        // FASE 11.7 — soft-delete do attachment anterior (mesma categoria) antes do novo upload.
        if ($nota->exists) {
            $this->softDeleteFechamentoNotaByCategory($nota, $tipo);
        }

        // Novo upload reseta o status do documento para pendente + grava o valor declarado.
        $nota->{$tipo . '_status'}        = FechamentoNota::STATUS_PENDING;
        $nota->{$tipo . '_reject_reason'} = null;
        $nota->{$tipo . '_decided_by'}    = null;
        $nota->{$tipo . '_decided_at'}    = null;
        $nota->{$tipo . '_valor'}         = $valor;
        $nota->save();

        // FASE 11.7 — Persistência 100% na camada Attachment.
        $this->registerFechamentoNota($nota, $tipo, $file, $path);

        return response()->json(['ok' => true, 'notas' => $nota->fresh()->toRowPayload()]);
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

        // Sinaliza (sem travar) quando o valor declarado ficou diferente do recebimento atual.
        $esperado = $this->recebimentoEsperado($type, (int) $id, $yearMonth);
        return response()->json([
            'notas' => $nota ? $nota->rowPayloadWithStale($esperado) : FechamentoNota::emptyRowPayload(),
        ]);
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

        if (!$nota) {
            abort(404, 'Anexo não encontrado');
        }

        // FASE 11.7 — Doc vem 100% da camada Attachment.
        $att = \App\Models\Attachment::query()
            ->forEntity('FECHAMENTO_NOTA', $nota->id)
            ->ofCategory($tipo)
            ->visible()
            ->latest('id')
            ->first();
        if (!$att || !Storage::disk(self::DISK)->exists($att->storage_path)) {
            abort(404, 'Anexo não encontrado');
        }

        return Storage::disk(self::DISK)->download($att->storage_path, $att->original_name ?: basename($att->storage_path));
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
            // 'revoke' = revogar uma nota já aceita (volta pra pendente, com motivo).
            'decisao' => 'required|in:accepted,rejected,revoke',
            'motivo'  => 'nullable|string|max:1000',
        ]);
        $needsMotivo = in_array($validated['decisao'], [FechamentoNota::STATUS_REJECTED, 'revoke'], true);
        if ($needsMotivo && empty(trim($validated['motivo'] ?? ''))) {
            return response()->json(['error' => 'Motivo é obrigatório.'], 422);
        }

        [$model] = $this->entity($type, $id);

        $nota = FechamentoNota::where('notable_type', get_class($model))
            ->where('notable_id', $model->id)
            ->where('year_month', $yearMonth)
            ->first();

        if (!$nota) {
            return response()->json(['error' => 'Não há nota enviada para decidir'], 422);
        }
        // FASE 11.7 — verifica via Attachment (não mais via _path inline).
        $hasFile = \App\Models\Attachment::query()
            ->forEntity('FECHAMENTO_NOTA', $nota->id)
            ->ofCategory($tipo)
            ->visible()
            ->exists();
        if (!$hasFile) {
            return response()->json(['error' => 'Não há nota enviada para decidir'], 422);
        }

        // Revogar volta o status para "pendente"; o motivo da revogação fica registrado.
        $nota->{$tipo . '_status'}        = $validated['decisao'] === 'revoke'
            ? FechamentoNota::STATUS_PENDING
            : $validated['decisao'];
        $nota->{$tipo . '_reject_reason'} = $needsMotivo ? trim($validated['motivo']) : null;
        $nota->{$tipo . '_decided_by'}    = $user->id;
        $nota->{$tipo . '_decided_at'}    = now();
        $nota->save();

        return response()->json(['ok' => true, 'notas' => $nota->toRowPayload()]);
    }

    /** POST .../fechamento/notas/{type}/{id}/{yearMonth}/liberar — admin libera envio após o prazo. */
    public function liberar(string $type, int $id, string $yearMonth): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'Apenas administradores podem liberar o envio'], 403);
        }
        [$model, $isPj] = $this->entity($type, $id);
        if (!$isPj) {
            return response()->json(['error' => 'Notas fiscais só se aplicam a entidades PJ'], 422);
        }

        $nota = FechamentoNota::firstOrCreate([
            'notable_type' => get_class($model),
            'notable_id'   => $model->id,
            'year_month'   => $yearMonth,
        ]);
        $nota->upload_liberado = true;
        $nota->liberado_por    = $user->name;
        $nota->liberado_em     = now();
        $nota->save();

        return response()->json(['ok' => true, 'notas' => $nota->toRowPayload()]);
    }

    /**
     * Recebimento esperado do notable no mês — o MESMO valor que o admin vê no fechamento
     * (já com desconto/adiantamento/adicional). O valor declarado na nota deve bater.
     * Retorna null se não conseguir determinar (não bloqueia o upload nesse caso).
     */
    private function recebimentoEsperado(string $type, int $id, string $yearMonth): ?float
    {
        try {
            if ($type === 'consultor') {
                $data = app(FechamentoConsultorController::class)->buildConsultoresData($yearMonth);
                $row  = collect($data['horistas'] ?? [])
                    ->merge($data['banco_horas'] ?? [])
                    ->merge($data['fixos'] ?? [])
                    ->firstWhere('user_id', $id);
                return $row ? round((float) ($row['recebimento'] ?? 0), 2) : null;
            }
            if ($type === 'parceiro') {
                $req  = Request::create('', 'GET', ['year_month' => $yearMonth]);
                $rows = app(FechamentoParceiroController::class)->index($req)->getData(true)['data'] ?? [];
                $row  = collect($rows)->firstWhere('partner_id', $id);
                return $row ? round((float) ($row['recebimento'] ?? 0), 2) : null;
            }
        } catch (\Throwable $e) {
            \Log::warning('recebimentoEsperado: falha ao calcular recebimento do fechamento', [
                'type' => $type, 'id' => $id, 'year_month' => $yearMonth, 'error' => $e->getMessage(),
            ]);
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FASE 11.7 — Helpers da camada Attachment (fonte única).
    //
    // A FechamentoNota é polimórfica (notable_type=User|Partner). No registry o
    // entity_type é 'FECHAMENTO_NOTA' e entity_id = fechamento_notas.id (a row
    // de notas, não o user/partner). category distingue os 2 documentos da row:
    // nfse | nota_debito.
    // ──────────────────────────────────────────────────────────────────────────

    private function registerFechamentoNota(FechamentoNota $nota, string $tipo, \Illuminate\Http\UploadedFile $file, string $path): void
    {
        $actor = Auth::user();
        if (!$actor) {
            throw new \RuntimeException("Não há ator pra registrar nota do fechamento {$nota->id}");
        }
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
    }

    private function softDeleteFechamentoNotaByCategory(FechamentoNota $nota, string $tipo): void
    {
        try {
            \App\Models\Attachment::query()
                ->forEntity('FECHAMENTO_NOTA', $nota->id)
                ->ofCategory($tipo)
                ->whereNull('deleted_at')
                ->get()
                ->each(fn ($att) => $att->delete()); // SoftDeletes
        } catch (\Throwable $e) {
            \Log::warning('FECHAMENTO_NOTA soft-delete falhou', [
                'fechamento_nota_id' => $nota->id,
                'tipo'               => $tipo,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
