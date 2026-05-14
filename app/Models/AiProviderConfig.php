<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de um provedor de IA (Anthropic, OpenAI, Gemini, ...).
 *
 * IMPORTANTE: a API key NUNCA é armazenada aqui. O campo `api_key_env`
 * guarda apenas o NOME da env var (ex.: 'ANTHROPIC_API_KEY'). A key real
 * é lida em runtime via `env($this->api_key_env)`.
 */
class AiProviderConfig extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'slug', 'name', 'api_key_env', 'base_url', 'default_model', 'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function resolveApiKey(): ?string
    {
        return env($this->api_key_env) ?: null;
    }

    public function hasKey(): bool
    {
        return ! empty($this->resolveApiKey());
    }
}
