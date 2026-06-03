<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class InvalidMimeTypeException extends RuntimeException
{
    public static function for(string $entityType, string $mime, array $allowed): self
    {
        return new self("MIME '{$mime}' não permitido para '{$entityType}' — permitidos: " . implode(', ', $allowed));
    }
}
