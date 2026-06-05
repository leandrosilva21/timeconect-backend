<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Comunicado de reajuste contratual enviado AO CLIENTE no momento da aplicação.
 * Tom formal, tema claro (igual aos e-mails de cliente). Informa índice, período,
 * percentual e o valor anterior → novo, com data de vigência.
 */
class ReajusteClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $cliente,
        public ?string $contrato,
        public float $valorAnterior,
        public float $valorNovo,
        public float $percentual,
        public string $indice,
        public ?string $periodoFormatado,
        public string $vigencia,
    ) {
    }

    public function envelope(): Envelope
    {
        $ref = $this->contrato ? " — {$this->contrato}" : '';
        return new Envelope(subject: "Comunicado de reajuste contratual{$ref}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contracts.reajuste-cliente');
    }
}
