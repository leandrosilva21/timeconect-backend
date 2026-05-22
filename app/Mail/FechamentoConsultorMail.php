<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail do fechamento de consultor.
 *
 * De  = conta autenticada (mail.from.address) COM o nome do usuário logado.
 *       NÃO usa o e-mail do usuário no From: o O365 bloqueia Send As cross-domain.
 * Reply-To / CC = financeiro@erpserv.com.br (mail.financeiro_cc).
 * To  = consultor (definido no Mail::to()).
 *
 * Corpo minimalista; o detalhamento vai nos anexos PDF + XLSX.
 */
class FechamentoConsultorMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string|null      $messageId      Message-ID custom (sem os <>) que setamos no header desta mensagem.
     * @param string|null      $references     Message-ID da mensagem-pai (References/In-Reply-To) para threading.
     * @param string|null      $threadKey      "consultorId:yearMonth" — vai no header X-Minutor-Fechamento-Id.
     * @param string|null      $bodyText       Texto livre (continuações) — exibido antes do conteúdo padrão.
     * @param bool             $isContinuation Se é uma continuação/resposta da thread (vs. envio original).
     * @param bool             $withAttachments Anexar PDF+XLSX (sempre no original; opcional nas continuações).
     */
    public function __construct(
        public string $consultantName,
        public string $senderName,
        public string $periodo,
        public string $valorTotal,
        public string $financeiroCc,
        public string $subjectLine,
        public string $pdfPath,
        public string $xlsxPath,
        public string $pdfFileName,
        public string $xlsxFileName,
        public ?string $messageId = null,
        public ?string $references = null,
        public ?string $threadKey = null,
        public ?string $bodyText = null,
        public bool $isContinuation = false,
        public bool $withAttachments = true,
    ) {
    }

    public function envelope(): Envelope
    {
        $from    = config('mail.from.address');
        $replyTo = [];
        if ($this->financeiroCc) {
            $replyTo[] = new Address($this->financeiroCc, 'Financeiro ERPSERV');
        }

        return new Envelope(
            from: new Address($from, $this->senderName),
            replyTo: $replyTo,
            cc: $this->financeiroCc ? [$this->financeiroCc] : [],
            subject: $this->subjectLine,
        );
    }

    /**
     * Headers de threading + matching:
     *  - Message-ID determinístico (fech-...@minutor.com.br) por mensagem;
     *  - References = Message-ID da 1ª mensagem da thread (continuações);
     *  - X-Minutor-Fechamento-Id = "consultorId:yearMonth" para matching robusto de inbound futuro.
     */
    public function headers(): Headers
    {
        $text = [];
        if ($this->threadKey) {
            $text['X-Minutor-Fechamento-Id'] = $this->threadKey;
        }

        return new Headers(
            messageId: $this->messageId ?: null,
            references: $this->references ? [$this->references] : [],
            text: $text,
        );
    }

    public function content(): Content
    {
        return new Content(
            // Continuação = e-mail simples (texto livre + assinatura), NÃO reenvia o template do fechamento.
            view: $this->isContinuation ? 'emails.fechamento.continuacao' : 'emails.fechamento.consultor',
            with: [
                'consultantName' => $this->consultantName,
                'senderName'     => $this->senderName,
                'periodo'        => $this->periodo,
                'valorTotal'     => $this->valorTotal,
                'financeiroCc'   => $this->financeiroCc,
                'bodyText'       => $this->bodyText,
                'isContinuation' => $this->isContinuation,
                'withAttachments' => $this->withAttachments,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        // Continuações podem ser enviadas sem anexos (controlado pelo controller).
        if (!$this->withAttachments) {
            return [];
        }

        return [
            Attachment::fromPath($this->pdfPath)
                ->as($this->pdfFileName)
                ->withMime('application/pdf'),
            Attachment::fromPath($this->xlsxPath)
                ->as($this->xlsxFileName)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
