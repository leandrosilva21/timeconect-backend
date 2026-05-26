<?php

namespace App\Enums;

enum FeedSource: string
{
    case System       = 'system';
    case Ai           = 'ai';
    case Movidesk     = 'movidesk';
    case Manual       = 'manual';
    case HealthEngine = 'health_engine';
    case CsEngine     = 'cs_engine';
    case Finance      = 'finance';
    case Training     = 'training';

    public function label(): string
    {
        return match ($this) {
            self::System       => 'Sistema',
            self::Ai           => 'IA',
            self::Movidesk     => 'Movidesk',
            self::Manual       => 'Manual',
            self::HealthEngine => 'Health Engine',
            self::CsEngine     => 'CS Engine',
            self::Finance      => 'Financeiro',
            self::Training     => 'Treinamento',
        };
    }
}
