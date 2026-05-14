<?php

namespace App\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group  = 'group';
    case Bot    = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direta',
            self::Group  => 'Grupo',
            self::Bot    => 'BOT',
        };
    }
}
