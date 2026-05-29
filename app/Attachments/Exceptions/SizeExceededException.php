<?php

namespace App\Attachments\Exceptions;

use RuntimeException;

class SizeExceededException extends RuntimeException
{
    public static function for(string $entityType, int $sizeBytes, int $maxMb): self
    {
        $sizeMb = number_format($sizeBytes / 1048576, 1, ',', '.');
        return new self("Arquivo de {$sizeMb} MB excede o limite de {$maxMb} MB para '{$entityType}'.");
    }
}
