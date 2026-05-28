<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class AttachmentPermissionException extends RuntimeException
{
    public static function for(string $action, string $entityType, ?int $entityId = null): self
    {
        $id = $entityId !== null ? "#{$entityId}" : '';
        return new self("Sem permissão para '{$action}' em {$entityType}{$id}");
    }
}
