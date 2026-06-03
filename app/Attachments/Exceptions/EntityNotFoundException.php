<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class EntityNotFoundException extends RuntimeException
{
    public static function for(string $entityType, int $entityId): self
    {
        return new self("Entidade {$entityType}#{$entityId} não encontrada (ou soft-deleted) — anexo recusado.");
    }
}
