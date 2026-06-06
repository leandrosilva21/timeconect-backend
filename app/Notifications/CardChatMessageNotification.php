<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notificação de nova mensagem em chat de card (requisição ou projeto).
 * Destinatário arbitrário (email avulso ou User).
 */
class CardChatMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $cardType,          // 'contract_request' | 'project'
        public string $cardCode,          // ex: 'REQ-2026-0312' | 'PRJ-1142'
        public string $cardTitle,         // ex: 'Migração para Protheus'
        public string $authorName,        // quem postou
        public string $authorRole,        // 'Executivo' | 'Coordenador' etc
        public string $messageExcerpt,    // texto da mensagem (~280 chars)
        public string $openUrl,           // link pra abrir o chat
        public string $cardUrl,           // link pro detalhe
        public string $recipientName,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isRequest = $this->cardType === 'contract_request';
        $eyebrow = $isRequest ? 'Chat de requisição' : 'Chat interno do projeto';
        $subjectPrefix = $isRequest ? '[Time Conect]' : '[Time Conect · Interno]';
        $subjectNoun  = $isRequest ? 'requisição' : 'projeto';
        $subject = "{$subjectPrefix} Nova mensagem na {$subjectNoun} {$this->cardCode} — {$this->cardTitle}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.cards.chat-message', [
                'eyebrow'       => $eyebrow,
                'cardType'      => $this->cardType,
                'cardCode'      => $this->cardCode,
                'cardTitle'     => $this->cardTitle,
                'authorName'    => $this->authorName,
                'authorRole'    => $this->authorRole,
                'messageExcerpt'=> $this->messageExcerpt,
                'openUrl'       => $this->openUrl,
                'cardUrl'       => $this->cardUrl,
                'recipientName' => $this->recipientName,
            ]);
    }
}
