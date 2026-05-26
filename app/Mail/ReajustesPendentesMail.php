<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta enviado ao Financeiro no 1º dia útil do mês: contratos com reajuste
 * VENCIDO (pendentes de aplicação). Lista cliente, valor, vencimento, índice e
 * impacto estimado, com link para o dashboard de reajustes.
 */
class ReajustesPendentesMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array<string,mixed>>  $contratos  linhas com reajuste vencido
     */
    public function __construct(
        public array $contratos,
        public float $totalImpacto,
        public string $referencia,
        public ?string $dashboardUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $n = count($this->contratos);
        return new Envelope(
            subject: "⚠️ {$n} contrato(s) pendente(s) de reajuste — {$this->referencia}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contracts.reajustes-pendentes');
    }
}
