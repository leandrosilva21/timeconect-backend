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
        Log::warning('🎫 [MOVIDESK WEBHOOK] ===== RECEBIDO =====', ['ip' => $request->ip()]);

        try {
            $payload = $request->all();

            if (empty($payload)) {
                return response()->json(['status' => 'error', 'message' => 'Payload vazio'], 400);
            }

            $ticketId = $payload['Id'] ?? null;

            Log::warning('🎫 [MOVIDESK WEBHOOK] Ticket recebido', [
                'ticket_id' => $ticketId,
                'subject'   => $payload['Subject'] ?? 'N/A',
                'status'    => $payload['Status'] ?? 'N/A',
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
