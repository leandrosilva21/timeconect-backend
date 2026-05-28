<?php

namespace App\Attachments;

/**
 * FASE 11 — Relatório de integridade de UM anexo. Usado por:
 *   - AttachmentService::integrityCheck($att)
 *   - Comando attachments:integrity-check (agregado em totais)
 *
 * Imutável após `add()`. Não tem setters de status — é monolítico por design.
 */
class IntegrityReport
{
    public const FAILURE_ENTITY_MISSING            = 'entity_missing';
    public const FAILURE_FILE_MISSING              = 'file_missing';
    public const FAILURE_CHECKSUM_MISMATCH         = 'checksum_mismatch';
    public const FAILURE_CHECKSUM_ERROR            = 'checksum_error';
    public const FAILURE_STORAGE_PROVIDER_MISMATCH = 'storage_provider_mismatch';

    private array $failures = [];

    public function __construct(public readonly int $attachmentId) {}

    public function add(string $reason, ?string $detail = null): void
    {
        $this->failures[] = ['reason' => $reason, 'detail' => $detail];
    }

    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return !empty($this->failures);
    }

    public function isHealthy(): bool
    {
        return !$this->hasFailures();
    }

    public function summary(): string
    {
        if ($this->isHealthy()) return 'OK';
        return implode('; ', array_map(
            fn ($f) => $f['reason'] . ($f['detail'] ? " ({$f['detail']})" : ''),
            $this->failures,
        ));
    }
}
