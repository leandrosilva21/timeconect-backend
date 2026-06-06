<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notificação de movimentação de fase do card (requisição ou projeto).
 * Cliente continua recebendo este tipo mesmo após req_decided_at — é status do projeto, não chat.
 */
class CardPhaseMovementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $cardType,       // 'contract_request' | 'project'
        public string $cardCode,
        public string $cardTitle,
        public string $fromColumn,     // ex: 'Em planejamento'
        public string $toColumn,       // ex: 'Em execução'
        public string $movedByName,
        public string $movedByRole,
        public ?string $note,          // observação opcional
        public string $cardUrl,
        public string $recipientName,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subjectNoun = $this->cardType === 'contract_request' ? 'Requisição' : 'Projeto';
        $subject = "[Time Conect] {$subjectNoun} {$this->cardCode} avançou para {$this->toColumn}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.cards.phase-movement', [
                'cardType'      => $this->cardType,
                'cardCode'      => $this->cardCode,
                'cardTitle'     => $this->cardTitle,
                'fromColumn'    => $this->fromColumn,
                'toColumn'      => $this->toColumn,
                'movedByName'   => $this->movedByName,
                'movedByRole'   => $this->movedByRole,
                'note'          => $this->note,
                'cardUrl'       => $this->cardUrl,
                'recipientName' => $this->recipientName,
            ]);
    }
}
