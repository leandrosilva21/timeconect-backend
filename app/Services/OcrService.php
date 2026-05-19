<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrService
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION  = '2023-06-01';
    private const MAX_BYTES          = 5 * 1024 * 1024;

    private const IMAGE_MEDIA_TYPES = [
        'image/png'  => true,
        'image/jpeg' => true,
        'image/jpg'  => true,
        'image/gif'  => true,
        'image/webp' => true,
    ];

    /**
     * Recebe a lista de `attachments` de uma action do Movidesk e devolve o
     * texto agregado extraído de TODAS as imagens via Claude Vision.
     * Retorna null se não houver imagem ou se a chamada falhar.
     */
    public function extractTextFromAttachments(array $attachments): ?string
    {
        Log::info('🔎 [OCR] extractTextFromAttachments entrou', [
            'configured'        => $this->isConfigured(),
            'attachments_count' => count($attachments),
            'first_keys'        => !empty($attachments) ? array_keys($attachments[0] ?? []) : [],
        ]);

        if (!$this->isConfigured()) {
            Log::warning('🔎 [OCR] ANTHROPIC_API_KEY ausente — fallback desligado');
            return null;
        }

        $extracted = [];
        foreach ($attachments as $i => $attachment) {
            $url       = $attachment['path']     ?? $attachment['url']      ?? null;
            $fileName  = $attachment['fileName'] ?? $attachment['filename'] ?? null;
            $mimeType  = $attachment['mimeType'] ?? $attachment['contentType'] ?? null;

            Log::info('🔎 [OCR] attachment candidato', [
                'index'      => $i,
                'has_url'    => (bool) $url,
                'fileName'   => $fileName,
                'mimeType'   => $mimeType,
                'is_image'   => $this->looksLikeImage($mimeType, $fileName),
            ]);

            if (!$url || !$this->looksLikeImage($mimeType, $fileName)) {
                continue;
            }

            $text = $this->extractFromImageUrl($url, $mimeType, $fileName);
            Log::info('🔎 [OCR] extração concluída', [
                'index'    => $i,
                'got_text' => $text !== null && trim($text) !== '',
                'text_len' => strlen((string) $text),
            ]);
            if ($text !== null && trim($text) !== '') {
                $extracted[] = trim($text);
            }
        }

        if (empty($extracted)) {
            Log::info('🔎 [OCR] nenhum texto extraído — retornando null');
            return null;
        }

        return implode("\n\n", $extracted);
    }

    private function extractFromImageUrl(string $url, ?string $mimeType, ?string $fileName): ?string
    {
        try {
            $response = Http::timeout(15)->get($url);
            if (!$response->successful()) {
                Log::warning('🔎 [OCR] Falha ao baixar imagem', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $bytes = $response->body();
            if (strlen($bytes) > self::MAX_BYTES) {
                Log::warning('🔎 [OCR] Imagem excede limite — pulando', [
                    'url'  => $url,
                    'size' => strlen($bytes),
                ]);
                return null;
            }

            $resolvedMime = $this->resolveMediaType($mimeType, $fileName, $response->header('Content-Type'));
            if (!$resolvedMime) {
                return null;
            }

            return $this->callClaudeVision(base64_encode($bytes), $resolvedMime);
        } catch (\Throwable $e) {
            Log::error('🔎 [OCR] Exceção ao extrair texto', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function callClaudeVision(string $base64, string $mediaType): ?string
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post(self::ANTHROPIC_ENDPOINT, [
                'model'      => config('services.anthropic.ocr_model'),
                'max_tokens' => 1024,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mediaType,
                                'data'       => $base64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Esta imagem é um anexo de uma interação de chamado helpdesk. Extraia EXATAMENTE o texto visível, preservando a ordem e quebras de linha. Não traduza, não resuma, não comente. Se a imagem não contiver texto legível, responda apenas com a palavra VAZIO.',
                        ],
                    ],
                ]],
            ]);

        if (!$response->successful()) {
            Log::warning('🔎 [OCR] Anthropic respondeu erro', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $text = $response->json('content.0.text');
        if (!is_string($text)) {
            return null;
        }

        $text = trim($text);
        if ($text === '' || strcasecmp($text, 'VAZIO') === 0) {
            return null;
        }

        return $text;
    }

    private function isConfigured(): bool
    {
        return (bool) config('services.anthropic.api_key');
    }

    private function looksLikeImage(?string $mimeType, ?string $fileName): bool
    {
        if ($mimeType && isset(self::IMAGE_MEDIA_TYPES[strtolower($mimeType)])) {
            return true;
        }
        if ($fileName) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
        }
        return false;
    }

    private function resolveMediaType(?string $declared, ?string $fileName, ?string $responseContentType): ?string
    {
        $candidates = [$declared, $responseContentType];
        foreach ($candidates as $candidate) {
            if (!$candidate) continue;
            $normalized = strtolower(trim(explode(';', $candidate)[0]));
            if ($normalized === 'image/jpg') $normalized = 'image/jpeg';
            if (isset(self::IMAGE_MEDIA_TYPES[$normalized])) {
                return $normalized;
            }
        }

        if ($fileName) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $map = [
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            ];
            return $map[$ext] ?? null;
        }

        return null;
    }
}
