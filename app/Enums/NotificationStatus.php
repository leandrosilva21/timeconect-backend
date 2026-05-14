<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Unread    = 'unread';
    case Read      = 'read';
    case Resolved  = 'resolved';
    case Archived  = 'archived';
    case Snoozed   = 'snoozed';

    public function label(): string
    {
        return match ($this) {
            self::Unread   => 'Não lida',
            self::Read     => 'Lida',
            self::Resolved => 'Resolvido',
            self::Archived => 'Arquivado',
            self::Snoozed  => 'Soneca',
        };
    }
}
