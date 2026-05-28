<?php

namespace App\Console\Commands;

use App\Attachments\AttachmentService;
use App\Attachments\Backfill\BackfillModule;
use App\Attachments\Backfill\ContractAttachmentBackfill;
use App\Attachments\Backfill\ContractMessageAttachmentBackfill;
use App\Attachments\Backfill\ExpenseReceiptBackfill;
use App\Attachments\Backfill\FechamentoNotaBackfill;
use App\Attachments\Backfill\HourContributionPropostaBackfill;
use App\Attachments\Backfill\ProjectAttachmentBackfill;
use App\Attachments\Backfill\ProjectMessageAttachmentBackfill;
use App\Attachments\Backfill\RequestMessageAttachmentBackfill;
use App\Attachments\Backfill\TimesheetAttachmentBackfill;
use App\Attachments\Backfill\UserAvatarBackfill;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * FASE 11.3 — Backfill de anexos legados pra tabela `attachments`.
 *
 * Cada módulo legado tem uma classe BackfillModule no `app/Attachments/Backfill/`
 * que sabe: como iterar registros candidatos, como extrair (entity_id, path,
 * original_name, mime), e qual category usar. Este comando só orquestra.
 *
 * Princípios:
 *  - **Idempotente**: rodar 2× não duplica (dedup por checksum no service).
 *  - **Dry-run**: imprime o que faria sem alterar nada.
 *  - **Skip-on-missing**: se o arquivo físico legado sumiu, registra como
 *    "orphan_file" no relatório e segue — não trava.
 *  - **Chunked**: 200 rows por vez (chunkById), suporta tabelas grandes.
 *
 * Uso:
 *   php artisan attachments:backfill --module=user-avatar
 *   php artisan attachments:backfill --module=user-avatar --dry-run
 *   php artisan attachments:backfill --module=user-avatar --limit=100
 *   php artisan attachments:backfill --all
 *
 * Quando rodar:
 *   - Após dual-write estabilizado por ~24h (garantia que novos uploads já
 *     gravam dual);
 *   - Manualmente — NÃO em cron (idempotente, mas ainda assim caro).
 */
class AttachmentsBackfill extends Command
{
    protected $signature = 'attachments:backfill
        {--module= : Nome do módulo (user-avatar, expense-receipt, ...). Obrigatório se --all não usado}
        {--all : Roda TODOS os módulos cadastrados}
        {--dry-run : Não grava nada, só imprime}
        {--limit=0 : Limita a 1ª N rows do módulo (0=sem limite)}';

    protected $description = 'FASE 11.3 — Backfill de anexos legados pra tabela attachments.';

    /**
     * Registro de módulos disponíveis. Adicionar nova entrada por módulo — fácil
     * descoberta via `--list`. Cada classe implementa BackfillModule.
     */
    private const MODULES = [
        'user-avatar'                 => UserAvatarBackfill::class,
        'expense-receipt'             => ExpenseReceiptBackfill::class,
        'timesheet-attachment'        => TimesheetAttachmentBackfill::class,
        'hourcontribution-proposta'   => HourContributionPropostaBackfill::class,
        'projectmessage-attachment'   => ProjectMessageAttachmentBackfill::class,
        'contractmessage-attachment'  => ContractMessageAttachmentBackfill::class,
        'requestmessage-attachment'   => RequestMessageAttachmentBackfill::class,
        'project-attachment'          => ProjectAttachmentBackfill::class,
        'contract-attachment'         => ContractAttachmentBackfill::class,
        'fechamento-nota'             => FechamentoNotaBackfill::class,
        // STAGE_ACTIVITY_EVENT pendente: 3 controllers de stage ainda não estão em prod
        // (feature cronograma em desenvolvimento). Adicionar quando chegarem.
    ];

    public function handle(AttachmentService $service): int
    {
        $module = $this->option('module');
        $all    = $this->option('all');
        $dryRun = $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        if (!$module && !$all) {
            $this->error('Use --module=<nome> OU --all. Módulos disponíveis: ' . implode(', ', array_keys(self::MODULES)));
            return self::FAILURE;
        }

        $modules = $all ? self::MODULES : [$module => self::MODULES[$module] ?? null];

        $totalRunStats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        foreach ($modules as $name => $class) {
            if ($class === null) {
                $this->error("Módulo '{$name}' não registrado. Disponíveis: " . implode(', ', array_keys(self::MODULES)));
                return self::FAILURE;
            }

            $this->info(PHP_EOL . "━━ Módulo: {$name} ━━");
            /** @var BackfillModule $impl */
            $impl  = app($class);
            $stats = $impl->run($service, $this, $dryRun, $limit);

            foreach ($stats as $k => $v) {
                $totalRunStats[$k] = ($totalRunStats[$k] ?? 0) + $v;
            }
        }

        $this->newLine();
        $this->info('━━ Total ━━');
        $this->table(
            ['metric', 'count'],
            collect($totalRunStats)->map(fn ($n, $k) => ['metric' => $k, 'count' => $n])->values()->all()
        );

        if ($dryRun) {
            $this->warn('DRY-RUN: nada foi gravado.');
        }

        return ($totalRunStats['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
