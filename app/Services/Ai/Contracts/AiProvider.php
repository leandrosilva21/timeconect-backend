<?php

namespace App\Services\Ai\Contracts;

interface AiProvider
{
    /**
     * Gera um insight textual a partir de um prompt + contexto estruturado.
     *
     * @param  string $prompt   Texto principal (papel/instrução do usuário).
     * @param  array  $context  Dados estruturados; serão serializados como JSON e anexados ao prompt.
     * @param  array  $options  system, model, temperature, max_tokens, response_format (driver-specific).
     */
    public function generateInsight(string $prompt, array $context = [], array $options = []): string;

    public function name(): string;
}
