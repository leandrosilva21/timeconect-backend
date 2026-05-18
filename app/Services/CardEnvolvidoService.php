<?php

namespace App\Services;

use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ContractRequestMessage;
use App\Models\User;
use Illuminate\Support\Collection;

class CardEnvolvidoService
{
    /**
     * Destinatários de notificação de CHAT.
     * - Chat de requisição (aberta):    todos envolvidos (interno + cliente)
     * - Chat de requisição (decidida):  só envolvidos interno  (cliente sai)
     * - Chat de projeto:                só envolvidos interno  (cliente nunca)
     *
     * Fallback automático: se nenhum envolvido cadastrado, infere pelo contexto:
     *  - Requisição: criador + executivo da conta + autores anteriores de mensagens
     *  - Projeto: coordenadores + autores anteriores de mensagens (cliente NUNCA)
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

        $envolvidos = $q->get()
            ->reject(fn($e) => $excludeUserId && $e->user_id === $excludeUserId)
            ->reject(fn($e) => empty($e->notification_email))
            ->map(fn($e) => [
                'display_name' => $e->display_name,
                'email'        => $e->notification_email,
                'user_id'      => $e->user_id,
                'side'         => $e->side,
            ])
            ->values();

        // Fallback: card sem envolvidos cadastrados — infere pelo contexto.
        // (Não usa fallback se já tem alguém — respeita configuração explícita.)
        if ($envolvidos->isEmpty()) {
            return $this->fallbackChatRecipients($cardType, $cardId, $clientBlocked, $excludeUserId);
        }

        return $envolvidos;
    }

    /**
     * Destinatários de notificação de MOVIMENTAÇÃO DE FASE.
     * Cliente recebe SEMPRE (antes e depois de virar projeto), porque é um evento
     * de status do projeto, não de chat.
     *
     * Fallback automático: se nenhum envolvido cadastrado, infere pelo contexto
     * (sem bloqueio de cliente — movimentação visível pra todos).
     *
     * @return Collection<int, array{display_name:string, email:string, user_id:?int, side:string}>
     */
    public function recipientsForMovement(string $cardType, int $cardId, ?int $excludeUserId = null): Collection
    {
        $envolvidos = CardEnvolvido::forCard($cardType, $cardId)
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

        if ($envolvidos->isEmpty()) {
            // Movimentação inclui cliente — clientBlocked=false
            return $this->fallbackChatRecipients($cardType, $cardId, false, $excludeUserId);
        }

        return $envolvidos;
    }

    /**
     * Fallback quando não há envolvidos cadastrados explicitamente.
     * - REQUEST: criador (cliente) + executivo da conta + autores prévios + watchers
     * - PROJECT: coordenadores + autores prévios; cliente NUNCA pra chat
     */
    private function fallbackChatRecipients(string $cardType, int $cardId, bool $clientBlocked, ?int $excludeUserId): Collection
    {
        $out = collect();
        $seen = [];

        $addUser = function (?User $u, string $side) use (&$out, &$seen, $excludeUserId) {
            if (!$u || !$u->email) return;
            if ($excludeUserId && $u->id === $excludeUserId) return;
            $key = strtolower($u->email);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out->push([
                'display_name' => $u->name ?? $u->email,
                'email'        => $u->email,
                'user_id'      => $u->id,
                'side'         => $side,
            ]);
        };

        $addEmail = function (?string $email, string $name, string $side) use (&$out, &$seen) {
            if (!$email) return;
            $key = strtolower($email);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out->push([
                'display_name' => $name ?: $email,
                'email'        => $email,
                'user_id'      => null,
                'side'         => $side,
            ]);
        };

        if ($cardType === CardEnvolvido::TYPE_REQUEST) {
            $req = ContractRequest::with(['createdBy', 'customer', 'watchers.user'])->find($cardId);
            if (!$req) return $out;

            // Criador (cliente ou interno que abriu a req)
            $creator = $req->createdBy;
            if ($creator) {
                $creatorIsClient = $creator->type === 'cliente';
                if (!($clientBlocked && $creatorIsClient)) {
                    $addUser($creator, $creatorIsClient ? 'client' : 'internal');
                }
            }

            // Executivo da conta (sempre interno)
            if ($req->customer?->executive_id) {
                $exec = User::find($req->customer->executive_id);
                $addUser($exec, 'internal');
            }

            // Watchers
            foreach ($req->watchers ?? [] as $w) {
                if ($w->user) {
                    $isClient = $w->user->type === 'cliente';
                    if (!($clientBlocked && $isClient)) {
                        $addUser($w->user, $isClient ? 'client' : 'internal');
                    }
                } elseif ($w->email) {
                    // Email externo sem user — assume side=client se o domínio não bate com ERPServ
                    $isClient = !str_contains(strtolower($w->email), '@erpserv.');
                    if (!($clientBlocked && $isClient)) {
                        $addEmail($w->email, $w->email, $isClient ? 'client' : 'internal');
                    }
                }
            }

            // Autores prévios de mensagens nesta req
            $authorIds = ContractRequestMessage::where('contract_request_id', $cardId)
                ->pluck('user_id')->unique()->filter()->values();
            foreach ($authorIds as $uid) {
                $u = User::find($uid);
                if (!$u) continue;
                $isClient = $u->type === 'cliente';
                if ($clientBlocked && $isClient) continue;
                $addUser($u, $isClient ? 'client' : 'internal');
            }

        } elseif ($cardType === CardEnvolvido::TYPE_PROJECT) {
            $project = Project::with('coordinators:id,name,email')->find($cardId);
            if (!$project) return $out;

            // Coordenadores
            foreach ($project->coordinators ?? [] as $c) {
                $addUser($c, 'internal');
            }

            // Autores prévios de mensagens
            $authorIds = ProjectMessage::where('project_id', $cardId)
                ->pluck('user_id')->unique()->filter()->values();
            foreach ($authorIds as $uid) {
                $u = User::find($uid);
                if (!$u) continue;
                if ($u->type === 'cliente') continue; // chat de projeto NUNCA pra cliente
                $addUser($u, 'internal');
            }
        }

        return $out->values();
    }
}
