<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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

    public function content(): Content
    {
        return new Content(
            view: 'emails.fechamento.consultor',
            with: [
                'consultantName' => $this->consultantName,
                'senderName'     => $this->senderName,
                'periodo'        => $this->periodo,
                'valorTotal'     => $this->valorTotal,
                'financeiroCc'   => $this->financeiroCc,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
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
