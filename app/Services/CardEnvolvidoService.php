<?php

namespace App\Services;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\Project;
use Illuminate\Support\Collection;

class CardEnvolvidoService
{
    /**
     * Destinatários de notificação de CHAT.
     * - Chat de requisição (aberta):    todos envolvidos (interno + cliente)
     * - Chat de requisição (decidida):  só envolvidos interno  (cliente sai)
     * - Chat de projeto:                só envolvidos interno  (cliente nunca)
     *
     * @return Collection<int, array{display_name:string, email:string, user_id:?int, side:string}>
     */
    public function recipientsForChat(string $cardType, int $cardId, ?int $excludeUserId = null): Collection
    {
        $q = CardEnvolvido::forCard($cardType, $cardId)->with('user:id,name,email');

        $clientBlocked = false;
        if ($cardType === CardEnvolvido::TYPE_PROJECT) {
            $clientBlocked = true;
        } elseif ($cardType === CardEnvolvido::TYPE_REQUEST) {
            $req = ContractRequest::find($cardId);
            if ($req && $req->req_decided_at) $clientBlocked = true;
        }

        if ($clientBlocked) $q->internal();

        return $q->get()
            ->reject(fn($e) => $excludeUserId && $e->user_id === $excludeUserId)
            ->reject(fn($e) => empty($e->notification_email))
            ->map(fn($e) => [
                'display_name' => $e->display_name,
                'email'        => $e->notification_email,
                'user_id'      => $e->user_id,
                'side'         => $e->side,
            ])
            ->values();
    }

    /**
     * Destinatários de notificação de MOVIMENTAÇÃO DE FASE.
     * Cliente recebe SEMPRE (antes e depois de virar projeto), porque é um evento
     * de status do projeto, não de chat.
     *
     * @return Collection<int, array{display_name:string, email:string, user_id:?int, side:string}>
     */
    public function recipientsForMovement(string $cardType, int $cardId, ?int $excludeUserId = null): Collection
    {
        return CardEnvolvido::forCard($cardType, $cardId)
            ->with('user:id,name,email')
            ->get()
            ->reject(fn($e) => $excludeUserId && $e->user_id === $excludeUserId)
            ->reject(fn($e) => empty($e->notification_email))
            ->map(fn($e) => [
                'display_name' => $e->display_name,
                'email'        => $e->notification_email,
                'user_id'      => $e->user_id,
                'side'         => $e->side,
            ])
            ->values();
    }
}
