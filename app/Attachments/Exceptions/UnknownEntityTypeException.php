<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class UnknownEntityTypeException extends RuntimeException
{
    public static function for(string $entityType): self
    {
        return new self("entity_type '{$entityType}' não está registrado no AttachableEntitiesRegistry");
    }
}
