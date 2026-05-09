<?php

namespace App\Http\Controllers;

use App\Services\MovideskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MovideskWebhookController extends Controller
{
    public function handleTicket(Request $request, MovideskService $service): JsonResponse
    {
        // Validação opcional de assinatura HMAC. Ative com MOVIDESK_WEBHOOK_VALIDATE=true
        // após configurar o secret no painel do Movidesk e em MOVIDESK_WEBHOOK_SECRET.
        if (config('services.movidesk.webhook_validate')) {
            $secret = (string) config('services.movidesk.webhook_secret');
            $signature = (string) $request->header('X-Movidesk-Signature', '');
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            if ($secret === '' || !hash_equals($expected, $signature)) {
                Log::warning('[MOVIDESK WEBHOOK] Assinatura inválida', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Assinatura inválida'], 401);
            }
        }

        try {
            $payload = $request->all();

            if (empty($payload)) {
                return response()->json(['status' => 'error', 'message' => 'Payload vazio'], 400);
            }

            $ticketId = $payload['Id'] ?? null;

            Log::info('[MOVIDESK WEBHOOK] Ticket recebido', [
                'ticket_id' => $ticketId,
                'status'    => $payload['Status'] ?? null,
            ]);

            if ($ticketId && config('services.movidesk.token')) {
                $ticketDetails = $service->fetchTicket((int) $ticketId);

                if ($ticketDetails) {
                    $created = $service->processTicket($ticketDetails);
                    Log::warning('🎫 [MOVIDESK WEBHOOK] Processado', ['timesheets_created' => $created]);
                } else {
                    Log::warning('🎫 [MOVIDESK WEBHOOK] Ticket não encontrado na API', ['ticket_id' => $ticketId]);
                }
            }

            return response()->json([
                'status'    => 'success',
                'message'   => 'Webhook processado',
                'timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK WEBHOOK] Erro', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            // Retorna 200 para o Movidesk não retentar
            return response()->json(['status' => 'error', 'message' => 'Erro interno'], 200);
        }
    }
}
