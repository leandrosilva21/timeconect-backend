<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica o executivo da conta quando o contrato entra em "Início Autorizado".
 * É o passo entre cadastro administrativo e geração de projeto — executivo
 * recebe pra acompanhar/atuar antes de virar projeto efetivo.
 */
class ContractInicioAutorizadoNotification extends Notification
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

        $base = rtrim((string) config('app.frontend_url', 'https://app.timeconect.com.br'), '/');
        $cardUrl = "{$base}/contratos/kanban";

        $recipientName = $notifiable instanceof AnonymousNotifiable
            ? 'executivo da conta'
            : ($notifiable->name ?? 'executivo da conta');

        return (new MailMessage)
            ->subject("[Time Conect] Contrato com início autorizado — {$codigo}")
            ->view('emails.contracts.inicio-autorizado', [
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'cardUrl'       => $cardUrl,
                'recipientName' => $recipientName,
            ]);
    }
}
