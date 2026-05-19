<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica área administrativa + criador quando um novo contrato é cadastrado
 * (fase de rascunho). Cliente ainda NÃO recebe — só recebe quando o projeto
 * é gerado a partir desse contrato.
 *
 * Síncrono (sem ShouldQueue) — evento raro, vale o custo ms da request HTTP
 * em troca de não depender de queue-worker estar rodando.
 */
class ContractCreatedNotification extends Notification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $c = $this->contract->loadMissing(['customer:id,name']);
        $codigo  = $c->code ?? '—';
        $projeto = $c->project_name ?? '—';
        $cliente = optional($c->customer)->name ?? '—';

        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');
        $cardUrl = "{$base}/contratos/kanban";

        $recipientName = $notifiable instanceof AnonymousNotifiable
            ? 'equipe administrativa'
            : ($notifiable->name ?? 'equipe administrativa');

        return (new MailMessage)
            ->subject("[Minutor] Novo contrato cadastrado — {$codigo}")
            ->view('emails.contracts.created', [
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'cardUrl'       => $cardUrl,
                'recipientName' => $recipientName,
            ]);
    }
}
