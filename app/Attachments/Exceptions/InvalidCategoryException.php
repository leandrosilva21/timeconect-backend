<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class InvalidCategoryException extends RuntimeException
{
    public static function for(string $entityType, string $category, array $allowed): self
    {
        return new self("Categoria '{$category}' não permitida para entity_type '{$entityType}' — permitidas: " . implode(', ', $allowed));
    }
}
