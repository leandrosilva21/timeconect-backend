<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AnthropicProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://api.anthropic.com/v1',
        protected int $timeoutSeconds = 60,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function generateInsight(string $prompt, array $context = [], array $options = []): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        $userContent = $prompt;
        if ($context) {
            $userContent .= "\n\nContext (JSON):\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $payload = [
            'model'      => $options['model']      ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout($this->timeoutSeconds)
            ->post("{$this->baseUrl}/messages", $payload);

        if (! $response->successful()) {
            Log::error('🤖 [AI/Anthropic] Erro na chamada', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("Anthropic API error: HTTP {$response->status()}");
        }

        $data = $response->json();
        $blocks = $data['content'] ?? [];

        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text);
    }

    /**
     * Chamada com tool-use. Retorna a estrutura crua da Anthropic
     * (stop_reason, content[], usage). Caller é responsável pelo loop
     * de tool execution + replays.
     *
     * @param  array<int,array{role:string,content:mixed}>  $messages
     * @param  array<int,array{name:string,description:string,input_schema:array}>  $tools
     */
    public function callWithTools(string $system, array $messages, array $tools, array $options = []): array
    {
        if (! $this->apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
        }

        $payload = [
            'model'      => $options['model']      ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'system'     => $system,
            'messages'   => $messages,
            'tools'      => $tools,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout($this->timeoutSeconds)
            ->post("{$this->baseUrl}/messages", $payload);

        if (! $response->successful()) {
            Log::error('🤖 [AI/Anthropic tools] Erro', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("Anthropic API error: HTTP {$response->status()} - " . $response->body());
        }

        return $response->json();
    }
}
