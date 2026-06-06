<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica ciclo de vida da requisição: criação + movimentação no kanban.
 * Dois estágios:
 *  - 'created' → tom acolhedor, explica próximo passo (arrastar pra novo projeto)
 *  - 'moved'   → notificação simples de avanço de fase
 *
 * Para de enviar quando vira projeto/contrato (req_decided_at preenchido) —
 * a partir daí o pipeline de movimentação de card assume.
 */
class ContractRequestLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $stage,            // 'created' | 'moved'
        public string $reqCode,
        public string $reqTitle,
        public string $customerName,
        public ?string $fromColumn,
        public string $toColumn,
        public string $cardUrl,
        public string $recipientName,
        public string $recipientRole,    // 'Solicitante' | 'Executivo da conta' | 'Acompanhando'
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->stage === 'created'
            ? "[Time Conect] Requisição {$this->reqCode} criada — próximos passos"
            : "[Time Conect] Requisição {$this->reqCode} avançou para " . $this->prettyColumn($this->toColumn);

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.requests.lifecycle', [
                'stage'         => $this->stage,
                'reqCode'       => $this->reqCode,
                'reqTitle'      => $this->reqTitle,
                'customerName'  => $this->customerName,
                'fromColumnLabel' => $this->fromColumn ? $this->prettyColumn($this->fromColumn) : null,
                'toColumnLabel' => $this->prettyColumn($this->toColumn),
                'cardUrl'       => $this->cardUrl,
                'recipientName' => $this->recipientName,
                'recipientRole' => $this->recipientRole,
            ]);
    }

    private function prettyColumn(string $col): string
    {
        return match ($col) {
            'backlog'               => 'Backlog',
            'novo_projeto'          => 'Novo Projeto',
            'em_planejamento'       => 'Em Planejamento',
            'em_validacao'          => 'Em Validação',
            'em_revisao'            => 'Em Revisão',
            'aprovado'              => 'Aprovado',
            'req_inicio_autorizado' => 'Aguardando Início (Req.)',
            'req_planejamento'      => 'Planejamento da requisição',
            'req_em_andamento'      => 'Em andamento (Req.)',
            'recusada'              => 'Recusada',
            'cancelada'             => 'Cancelada',
            default => ucfirst(str_replace('_', ' ', $col)),
        };
    }
}
