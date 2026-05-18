<?php

namespace App\Services;

use App\Models\ContractRequest;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\ContractRequestLifecycleNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Notifica o ciclo de vida da requisição (criação + cada movimentação no kanban)
 * pro cliente solicitante + executivo da conta. Para de disparar quando vira
 * contrato/projeto (req_decided_at preenchido).
 *
 * Destinatários (sempre best-effort, sem bloquear o fluxo principal):
 *  - Cliente: user que criou a requisição (created_by_id) OU email do user customer
 *  - Executivo da conta: customer.executive_id → user.email
 *  - Watchers: contract_request_watchers.email
 */
class ContractRequestNotifier
{
    /** Dispara quando a requisição acabou de ser criada. */
    public function created(ContractRequest $req): void
    {
        $this->dispatch($req, stage: 'created', fromColumn: null, toColumn: $req->kanban_column ?? 'backlog');
    }

    /** Dispara em movimentação de coluna no kanban. */
    public function moved(ContractRequest $req, ?string $from, ?string $to): void
    {
        if (!$to || $from === $to) return;
        $this->dispatch($req, stage: 'moved', fromColumn: $from, toColumn: $to);
    }

    private function dispatch(ContractRequest $req, string $stage, ?string $fromColumn, string $toColumn): void
    {
        try {
            // Após req_decided_at (virou projeto/contrato), cliente sai do loop.
            // Mas o executivo continua recebendo? Decisão: para com tudo aqui
            // — depois é o pipeline de movimentação de card que assume.
            if ($req->req_decided_at) return;

            $recipients = $this->resolveRecipients($req);
            if (empty($recipients)) return;

            $base = rtrim((string) config('app.url'), '/');
            $cardUrl = $base . '/contratos/pipeline?req=' . $req->id;
            $code = $req->code ?? ('REQ-' . str_pad((string) $req->id, 6, '0', STR_PAD_LEFT));
            $title = $req->title ?? ($req->descricao ? \Str::limit($req->descricao, 80) : ($req->area_requisitante ?? 'Requisição'));
            $customerName = $req->customer?->name ?? 'Cliente';

            foreach ($recipients as $r) {
                Notification::route('mail', $r['email'])->notify(new ContractRequestLifecycleNotification(
                    stage:          $stage,
                    reqCode:        $code,
                    reqTitle:       $title,
                    customerName:   $customerName,
                    fromColumn:     $fromColumn,
                    toColumn:       $toColumn,
                    cardUrl:        $cardUrl,
                    recipientName:  $r['display_name'],
                    recipientRole:  $r['role'],
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('contract_request lifecycle notif falhou', [
                'req_id' => $req->id, 'stage' => $stage, 'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int,array{display_name:string,email:string,role:string}>
     */
    private function resolveRecipients(ContractRequest $req): array
    {
        $out = [];
        $seen = [];

        $req->loadMissing(['customer', 'createdBy', 'watchers.user']);

        // 1) Criador (cliente) — autor da requisição
        $creator = $req->createdBy;
        if ($creator && $creator->email && !isset($seen[strtolower($creator->email)])) {
            $out[] = [
                'display_name' => $creator->name ?? $creator->email,
                'email'        => $creator->email,
                'role'         => 'Solicitante',
            ];
            $seen[strtolower($creator->email)] = true;
        }

        // 2) Executivo da conta — customer.executive_id
        $execId = $req->customer?->executive_id;
        if ($execId) {
            $exec = User::find($execId);
            if ($exec && $exec->email && !isset($seen[strtolower($exec->email)])) {
                $out[] = [
                    'display_name' => $exec->name ?? $exec->email,
                    'email'        => $exec->email,
                    'role'         => 'Executivo da conta',
                ];
                $seen[strtolower($exec->email)] = true;
            }
        }

        // 3) Watchers (cc_emails da requisição)
        foreach ($req->watchers ?? [] as $w) {
            $email = $w->user?->email ?? $w->email;
            if (!$email) continue;
            $key = strtolower($email);
            if (isset($seen[$key])) continue;
            $out[] = [
                'display_name' => $w->user?->name ?? $email,
                'email'        => $email,
                'role'         => 'Acompanhando',
            ];
            $seen[$key] = true;
        }

        return $out;
    }
}
