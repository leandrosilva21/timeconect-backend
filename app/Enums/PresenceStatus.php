<?php

namespace App\Enums;

enum PresenceStatus: string
{
    case Online  = 'online';
    case Away    = 'away';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online  => 'Online',
            self::Away    => 'Ausente',
            self::Offline => 'Offline',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Online  => '#10b981',
            self::Away    => '#f59e0b',
            self::Offline => '#6b7280',
        };
    }
}
