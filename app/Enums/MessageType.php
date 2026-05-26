<?php

namespace App\Enums;

enum MessageType: string
{
    case User      = 'user';
    case System    = 'system';
    case Bot       = 'bot';
    case AiInsight = 'ai_insight';
    case Alert     = 'alert';

    public function label(): string
    {
        return match ($this) {
            self::User      => 'Usuário',
            self::System    => 'Sistema',
            self::Bot       => 'BOT',
            self::AiInsight => 'Diagnóstico IA',
            self::Alert     => 'Alerta',
        };
    }
}
