<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeoutSeconds = 60,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function generateInsight(string $prompt, array $context = [], array $options = []): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException('OPENAI_API_KEY não configurada.');
        }

        $messages = [];

        if (isset($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }

        $userContent = $prompt;
        if ($context) {
            $userContent .= "\n\nContext (JSON):\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $payload = [
            'model'    => $options['model']    ?? $this->model,
            'messages' => $messages,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }
        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type'  => 'application/json',
        ])
            ->timeout($this->timeoutSeconds)
            ->post("{$this->baseUrl}/chat/completions", $payload);

        if (! $response->successful()) {
            Log::error('🤖 [AI/OpenAI] Erro na chamada', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("OpenAI API error: HTTP {$response->status()}");
        }

        $data = $response->json();
        return trim($data['choices'][0]['message']['content'] ?? '');
    }
}
