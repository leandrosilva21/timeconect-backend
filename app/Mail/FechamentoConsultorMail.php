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
 * Reply-To = a PRÓPRIA conta (noreply) — pra resposta do consultor cair na caixa que
 *       o Minutor lê via Graph (Fase 2) e encadear na thread. CC = financeiro (visibilidade).
 * To  = consultor (definido no Mail::to()).
 *
 * Corpo minimalista; o detalhamento vai nos anexos PDF + XLSX.
 */
class FechamentoConsultorMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string|null      $messageId      Message-ID custom (sem os <>) que setamos no header desta mensagem.
     * @param string|null      $references     Message-ID da mensagem-raiz (References) para threading.
     * @param string|null      $inReplyTo      Message-ID da mensagem-raiz (In-Reply-To) — Outlook threada por este header.
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
        public ?string $inReplyTo = null,
        public ?string $threadKey = null,
        public ?string $bodyText = null,
        public bool $isContinuation = false,
        public bool $withAttachments = true,
        public ?string $senderEmail = null,
        public ?string $mensagem = null, // corpo editável (texto livre); null = template usa default
        // From sobrescrito quando o remetente envia COMO ele mesmo (App Password O365).
        // Null em ambos = mantém o From padrão (config-based) — comportamento atual.
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $from = config('mail.from.address');
        // Reply-To = QUEM ENVIOU + FINANCEIRO: a resposta do consultor (um "Responder"
        // normal) volta pros DOIS, tratada no Outlook. Financeiro também vai em CC no envio.
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

        return new Envelope(
            from: $this->fromAddress
                ? new Address($this->fromAddress, $this->fromName ?? config('mail.fechamento_from_name', 'Fechamento ERPSERV'))
                : new Address($from, config('mail.fechamento_from_name', 'Fechamento ERPSERV')),
            replyTo: $replyTo,
            cc: $this->financeiroCc ? [$this->financeiroCc] : [],
            subject: $this->subjectLine,
        );
    }

    /**
     * Headers de threading + matching:
     *  - Message-ID determinístico (fech-...@minutor.com.br) por mensagem;
     *  - In-Reply-To = Message-ID da mensagem-raiz da thread (Outlook threada por este);
     *  - References = Message-ID da mensagem-raiz da thread;
     *  - X-Minutor-Fechamento-Id = "consultorId:yearMonth" para matching robusto de inbound futuro.
     *
     * O Illuminate\Mail\Mailables\Headers não tem campo dedicado para In-Reply-To,
     * então adicionamos via `text:` (addTextHeader não envolve em <>; envolvemos aqui),
     * garantindo exatamente um par de angle brackets.
     */
    public function headers(): Headers
    {
        $text = [];
        if ($this->threadKey) {
            $text['X-Minutor-Fechamento-Id'] = $this->threadKey;
        }
        if ($this->inReplyTo) {
            $id = trim($this->inReplyTo, '<>');
            $text['In-Reply-To'] = '<' . $id . '>';
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
                'mensagem'       => $this->mensagem ?? '',
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
