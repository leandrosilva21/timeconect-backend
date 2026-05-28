<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\ContractAttachment;
use App\Models\ContractMessageAttachment;
use App\Models\ContractRequestMessageAttachment;
use App\Models\Expense;
use App\Models\FechamentoNota;
use App\Models\HourContribution;
use App\Models\ProjectAttachment;
use App\Models\ProjectMessageAttachment;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * FASE 11.4 — Dry-run do drop legado.
 *
 * Para cada módulo migrado, conta:
 *   ok        — rows com legado preenchido E attachment correspondente vivo
 *   missing   — rows com legado preenchido MAS SEM attachment → seria PERDA se dropasse
 *   legacy_0  — rows com legado vazio (irrelevantes pro drop)
 *
 * Comando 100% read-only: NÃO executa ALTER TABLE, NÃO apaga nada.
 *
 * Saída: tabela por módulo. Coluna `safe_to_drop` = sim se missing=0.
 *
 * Uso:
 *   php artisan attachments:legacy-drop-preview
 *   php artisan attachments:legacy-drop-preview --json
 *
 * Quando rodar:
 *   - Após dual-write em produção por dias E backfill completo
 *   - Esperar TODOS os módulos com missing=0 antes de submeter a 11.4 migration
 *     que dropa colunas / tabelas legadas.
 */
class AttachmentsLegacyDropPreview extends Command
{
    protected $signature = 'attachments:legacy-drop-preview {--json : Output JSON}';
    protected $description = 'FASE 11.4 — Dry-run: relatório por módulo do que está pronto pra dropar legado.';

    public function handle(): int
    {
        $results = [
            'USER.profile_photo'                    => $this->probeColumn(User::class, 'profile_photo', 'USER', 'avatar'),
            'EXPENSE.receipt_path'                  => $this->probeColumn(Expense::class, 'receipt_path', 'EXPENSE', 'receipt'),
            'TIMESHEET.attachment_path'             => $this->probeColumn(Timesheet::class, 'attachment_path', 'TIMESHEET', 'attachment'),
            'HOUR_CONTRIBUTION.proposta_path'       => $this->probeColumn(HourContribution::class, 'proposta_path', 'HOUR_CONTRIBUTION', 'proposal'),
            'project_attachments (tabela)'          => $this->probeTable(ProjectAttachment::class, 'project_id', 'PROJECT'),
            'contract_attachments (tabela)'         => $this->probeTable(ContractAttachment::class, 'contract_id', 'CONTRACT'),
            'project_message_attachments'           => $this->probeTable(ProjectMessageAttachment::class, 'message_id', 'PROJECT_MESSAGE'),
            'contract_message_attachments'          => $this->probeTable(ContractMessageAttachment::class, 'message_id', 'CONTRACT_MESSAGE'),
            'contract_request_message_attachments'  => $this->probeTable(ContractRequestMessageAttachment::class, 'message_id', 'REQUEST_MESSAGE'),
            'FECHAMENTO_NOTA (nfse + nota_debito)'  => $this->probeFechamentoNotas(),
        ];

        // STAGE_ACTIVITY_EVENT (cronograma) — feature ainda não em prod; só prober
        // quando o model existir. Em dev/homolog vai aparecer normalmente.
        if (class_exists(\App\Models\StageActivityEvent::class)) {
            $results['STAGE_ACTIVITY_EVENT.attachment_path'] = $this->probeColumn(
                \App\Models\StageActivityEvent::class,
                'attachment_path',
                'STAGE_ACTIVITY_EVENT',
                'attachment'
            );
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('━━ FASE 11.4 — Preview do drop legado ━━');
        $this->line('');
        $this->table(
            ['Legado', 'Total legacy', 'Com attachment', 'Sem attachment ⚠', 'Safe to drop?'],
            collect($results)->map(fn ($r, $k) => [
                $k,
                $r['legacy_total'],
                $r['ok'],
                $r['missing'] > 0 ? '<fg=red>' . $r['missing'] . '</>' : '<fg=green>0</>',
                $r['missing'] === 0 ? '<fg=green>SIM</>' : '<fg=red>NÃO</>',
            ])->values()->all()
        );

        $totalMissing = array_sum(array_column($results, 'missing'));
        $this->line('');
        if ($totalMissing === 0) {
            $this->info("✓ Todos os módulos prontos. Pode prosseguir com a 11.4 migration.");
            return self::SUCCESS;
        }

        $this->warn("⚠ {$totalMissing} legacy(ies) sem attachment correspondente — rodar backfill antes do drop.");
        $this->line('');
        $this->line('Recomendação:');
        $this->line('  1. php artisan attachments:backfill --all  (re-roda; idempotente)');
        $this->line('  2. php artisan attachments:legacy-drop-preview (re-verifica)');
        $this->line('  3. Investigar manualmente os que persistem (arquivos físicos sumidos do disco)');
        return self::FAILURE;
    }

    /**
     * Probe pra coluna inline (USER.profile_photo, EXPENSE.receipt_path, etc).
     */
    private function probeColumn(string $modelClass, string $legacyColumn, string $entityType, string $category): array
    {
        $rowsWithLegacy = $modelClass::query()->whereNotNull($legacyColumn)->pluck('id');
        $legacyTotal = $rowsWithLegacy->count();

        if ($legacyTotal === 0) {
            return ['legacy_total' => 0, 'ok' => 0, 'missing' => 0];
        }

        // Quantos têm attachment vivo correspondente?
        $entityIdsWithAttachment = Attachment::query()
            ->where('entity_type', $entityType)
            ->where('category', $category)
            ->whereIn('entity_id', $rowsWithLegacy)
            ->whereNull('deleted_at')
            ->pluck('entity_id')
            ->unique();

        $ok = $entityIdsWithAttachment->count();
        $missing = $legacyTotal - $ok;

        return [
            'legacy_total' => $legacyTotal,
            'ok'           => $ok,
            'missing'      => $missing,
        ];
    }

    /**
     * Probe pra tabela dedicada (project_attachments, contract_attachments,
     * *_message_attachments). Cada row legada deve ter um attachment com
     * mesmo (entity_type, entity_id, storage_path).
     */
    private function probeTable(string $legacyAttachmentModel, string $entityIdCol, string $entityType): array
    {
        // Só rows com path real interessam — rows fantasmas (path=null, sem arquivo)
        // não têm o que migrar e seriam dropadas "vazias" no PR 7 sem perda.
        $pathCol = $this->pathColumnFor($legacyAttachmentModel);
        $legacyTotal = $legacyAttachmentModel::query()
            ->whereNotNull($pathCol)
            ->where($pathCol, '!=', '')
            ->count();
        if ($legacyTotal === 0) {
            return ['legacy_total' => 0, 'ok' => 0, 'missing' => 0];
        }

        $ok = 0;
        $legacyAttachmentModel::query()
            ->select('id', $entityIdCol, $pathCol)
            ->whereNotNull($pathCol)
            ->where($pathCol, '!=', '')
            ->chunkById(500, function ($chunk) use (&$ok, $entityType, $entityIdCol, $pathCol) {
                foreach ($chunk as $row) {
                    $exists = Attachment::query()
                        ->where('entity_type', $entityType)
                        ->where('entity_id', $row->{$entityIdCol})
                        ->where('storage_path', $row->{$pathCol})
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) $ok++;
                }
            });

        return [
            'legacy_total' => $legacyTotal,
            'ok'           => $ok,
            'missing'      => $legacyTotal - $ok,
        ];
    }

    private function probeFechamentoNotas(): array
    {
        // Cada row tem ATÉ 2 docs (nfse_path + nota_debito_path).
        $rows = FechamentoNota::query()
            ->whereNotNull('nfse_path')
            ->orWhereNotNull('nota_debito_path')
            ->get(['id', 'nfse_path', 'nota_debito_path']);

        $legacyTotal = 0;
        $ok = 0;
        foreach ($rows as $r) {
            foreach (['nfse', 'nota_debito'] as $tipo) {
                $path = $r->{$tipo . '_path'};
                if (!$path) continue;
                $legacyTotal++;
                $exists = Attachment::query()
                    ->where('entity_type', 'FECHAMENTO_NOTA')
                    ->where('entity_id', $r->id)
                    ->where('category', $tipo)
                    ->where('storage_path', $path)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) $ok++;
            }
        }

        return [
            'legacy_total' => $legacyTotal,
            'ok'           => $ok,
            'missing'      => $legacyTotal - $ok,
        ];
    }

    /**
     * Mapeia model legado → nome da coluna de path.
     */
    private function pathColumnFor(string $modelClass): string
    {
        return match ($modelClass) {
            ProjectAttachment::class, ContractAttachment::class => 'path',
            ProjectMessageAttachment::class,
            ContractMessageAttachment::class,
            ContractRequestMessageAttachment::class            => 'file_path',
            default                                            => 'path',
        };
    }
}
