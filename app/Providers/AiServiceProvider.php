<?php

namespace App\Providers;

use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            $driver = config('services.ai.default_provider', 'anthropic');

            return match ($driver) {
                'anthropic' => new AnthropicProvider(
                    apiKey: (string) config('services.anthropic.api_key'),
                    model: (string) config('services.anthropic.model'),
                    baseUrl: (string) config('services.anthropic.base_url'),
                ),
                'openai' => new OpenAiProvider(
                    apiKey: (string) config('services.openai.api_key'),
                    model: (string) config('services.openai.model'),
                    baseUrl: (string) config('services.openai.base_url'),
                ),
                default => throw new RuntimeException("AI provider desconhecido: {$driver}"),
            };
        });
    }
}
