<?php

namespace App\Enums;

enum FeedSeverity: string
{
    case Info     = 'info';
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info     => 'Informativo',
            self::Low      => 'Baixo',
            self::Medium   => 'Médio',
            self::High     => 'Alto',
            self::Critical => 'Crítico',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info     => '#3498db',
            self::Low      => '#2ecc71',
            self::Medium   => '#f39c12',
            self::High     => '#e67e22',
            self::Critical => '#e74c3c',
        };
    }
}
