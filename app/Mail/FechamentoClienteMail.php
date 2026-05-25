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
 * E-mail do fechamento de cliente.
 *
 * De  = conta autenticada (mail.from.address) COM o nome do usuário logado.
 *       NÃO usa o e-mail do usuário no From: o O365 bloqueia Send As cross-domain.
 * Reply-To = quem enviou (se houver) + financeiro (se houver) — fallback p/ a conta From.
 * To/CC  = NÃO definidos aqui; o controller os define via Mail::to()->cc().
 *
 * Corpo minimalista; o detalhamento vai nos anexos PDF + XLSX.
 */
class FechamentoClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param bool $withAttachments Anexar PDF+XLSX (default true).
     */
    public function __construct(
        public string $clienteName,
        public string $senderName,
        public string $periodo,
        public string $valorTotal,
        public string $subjectLine,
        public string $pdfPath,
        public string $xlsxPath,
        public string $pdfFileName,
        public string $xlsxFileName,
        public ?string $senderEmail = null,
        public ?string $financeiroCc = null,
        public ?string $mensagem = null, // corpo editável (texto livre); null = template usa default
        public array $projetos = [],     // [{codigo, nome}] — destaque dos projetos do fechamento
        public bool $withAttachments = true,
        // From sobrescrito quando o remetente envia COMO ele mesmo (App Password O365).
        // Null em ambos = mantém o From padrão (NF-e/config-based) — comportamento atual.
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = config('mail.from.address');
        // Reply-To = QUEM ENVIOU + FINANCEIRO: a resposta do cliente (um "Responder"
        // normal) volta pros DOIS, tratada no Outlook. Fallback p/ a conta From.
        $replyTo = [];
        if ($this->senderEmail) {
            $replyTo[] = new Address($this->senderEmail, $this->senderName);
        }
        if ($this->financeiroCc) {
            $replyTo[] = new Address($this->financeiroCc, 'Financeiro ERPSERV');
        }
        if (empty($replyTo)) {
            $replyTo[] = new Address($from, $this->senderName);
        }

        // To/CC ficam por conta do controller (Mail::to()->cc()).
        return new Envelope(
            from: $this->fromAddress
                ? new Address(
                    $this->fromAddress,
                    $this->fromName ?? config('mail.fechamento_cliente_from_name', config('mail.fechamento_from_name', 'Fechamento ERPSERV')),
                )
                : new Address(
                    config('mail.fechamento_cliente_from', $from),
                    config('mail.fechamento_cliente_from_name', config('mail.fechamento_from_name', 'Fechamento ERPSERV')),
                ),
            replyTo: $replyTo,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.fechamento.cliente',
            with: [
                'clienteName'     => $this->clienteName,
                'senderName'      => $this->senderName,
                'periodo'         => $this->periodo,
                'valorTotal'      => $this->valorTotal,
                'mensagem'        => $this->mensagem ?? '',
                'projetos'        => $this->projetos,
                'withAttachments' => $this->withAttachments,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
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
