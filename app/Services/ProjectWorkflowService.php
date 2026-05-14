<?php

namespace App\Services;

use App\Models\Project;

/**
 * Helper central de derivação do workflow de projetos.
 *
 * **REGRA ARQUITETURAL (ADR 0002):** o lifecycle real é `projects.status`.
 * Kanbans (contratos, operacional, executivo) são projeções visuais — NÃO
 * persistem coluna própria. Toda derivação de "qual coluna" passa por aqui.
 *
 * Nunca espalhar `if ($status === 'started')` por controllers/services/hooks.
 * Se precisar de um conjunto novo, adicione método aqui e referencie em toda
 * parte. Quem quebrar essa regra reabilita a duplicação que custou caro corrigir.
 */
class ProjectWorkflowService
{
    /**
     * Lifecycle completo, em ordem natural.
     */
    public const ORDER = [
        Project::STATUS_AWAITING_START,
        Project::STATUS_BACKLOG,
        Project::STATUS_PLANNING,
        Project::STATUS_STARTED,
        Project::STATUS_LIBERADO_PARA_TESTES,
        Project::STATUS_FINISHED,
        Project::STATUS_PAUSED,
        Project::STATUS_CANCELLED,
    ];

    /**
     * Projetos "vivos no pipeline" — não-terminais. Inclui backlog.
     * Use em listagens, dropdowns, visões de coordenação, kanban operacional.
     */
    public const PIPELINE = [
        Project::STATUS_AWAITING_START,
        Project::STATUS_BACKLOG,
        Project::STATUS_PLANNING,
        Project::STATUS_STARTED,
        Project::STATUS_LIBERADO_PARA_TESTES,
    ];

    /**
     * Projetos "em execução real" — apontamento real acontecendo.
     * Exclui backlog e awaiting_start. Use em SLA, produtividade, consumo
     * operacional, margem, capacidade real.
     */
    public const IN_EXECUTION = [
        Project::STATUS_STARTED,
        Project::STATUS_LIBERADO_PARA_TESTES,
    ];

    /**
     * Estados terminais. Não voltam atrás sem intervenção manual.
     */
    public const TERMINAL = [
        Project::STATUS_FINISHED,
        Project::STATUS_PAUSED,
        Project::STATUS_CANCELLED,
    ];

    /**
     * Mapeamento status → coluna visual do kanban operacional executivo.
     * `awaiting_start` é pré-kanban (sem coord ainda); fica fora.
     */
    public const OPERATIONAL_COLUMN = [
        Project::STATUS_BACKLOG               => 'backlog',
        Project::STATUS_PLANNING              => 'planning',
        Project::STATUS_STARTED               => 'execution',
        Project::STATUS_LIBERADO_PARA_TESTES  => 'homologation',
        Project::STATUS_FINISHED              => 'closed',
        Project::STATUS_PAUSED                => 'paused',
        Project::STATUS_CANCELLED             => 'cancelled',
    ];

    /**
     * Reverso — quando um DnD chega numa coluna, qual status setar.
     */
    public const STATUS_FOR_OPERATIONAL_COLUMN = [
        'backlog'       => Project::STATUS_BACKLOG,
        'planning'      => Project::STATUS_PLANNING,
        'execution'     => Project::STATUS_STARTED,
        'homologation'  => Project::STATUS_LIBERADO_PARA_TESTES,
        'closed'        => Project::STATUS_FINISHED,
        'paused'        => Project::STATUS_PAUSED,
        'cancelled'     => Project::STATUS_CANCELLED,
    ];

    public static function getOperationalColumn(?string $status): ?string
    {
        if ($status === null) return null;
        return self::OPERATIONAL_COLUMN[$status] ?? null;
    }

    public static function getStatusForOperationalColumn(string $column): ?string
    {
        return self::STATUS_FOR_OPERATIONAL_COLUMN[$column] ?? null;
    }

    public static function isPipeline(?string $status): bool
    {
        return $status !== null && in_array($status, self::PIPELINE, true);
    }

    public static function isInExecution(?string $status): bool
    {
        return $status !== null && in_array($status, self::IN_EXECUTION, true);
    }

    public static function isTerminal(?string $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL, true);
    }
}
