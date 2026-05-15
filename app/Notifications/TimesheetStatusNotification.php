<?php

namespace App\Notifications;

use App\Models\Timesheet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica o dono do apontamento sobre mudança de status (rejeitado, ajuste
 * solicitado, conflitante). Sempre enviado para $timesheet->user — substitui
 * o caminho antigo via n8n que estava redirecionando para o admin.
 */
class TimesheetStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Timesheet $timesheet;
    public string $statusKey; // REJEITADO | AJUSTE | CONFLITO
    public ?string $reason;

    public function __construct(Timesheet $timesheet, string $statusKey, ?string $reason = null)
    {
        $this->timesheet = $timesheet;
        $this->statusKey = $statusKey;
        $this->reason    = $reason;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $ts = $this->timesheet->loadMissing(['project.customer']);

        $minutes = (int) ($ts->effort_minutes ?? 0);
        $horas   = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        $data    = optional($ts->date)->format('d/m/Y') ?? '—';
        $projeto = optional($ts->project)->name ?? '—';
        $cliente = optional(optional($ts->project)->customer)->name ?? '—';

        [$assunto, $intro, $cta] = match ($this->statusKey) {
            'REJEITADO' => [
                'Apontamento rejeitado',
                'Seu apontamento foi rejeitado pelo aprovador. Revise os dados e crie um novo apontamento se necessário.',
                'Você precisa criar um novo apontamento corrigindo o problema descrito abaixo.',
            ],
            'AJUSTE' => [
                'Ajuste solicitado no apontamento',
                'O aprovador solicitou ajustes no seu apontamento. Por favor, revise e reenvie.',
                'Edite o apontamento original aplicando o ajuste descrito abaixo.',
            ],
            'CONFLITO' => [
                'Apontamento marcado como conflitante',
                'Seu apontamento entrou em conflito com outro do mesmo cliente no mesmo horário.',
                'Resolva o conflito ajustando os horários ou removendo um dos apontamentos.',
            ],
            default => ['Atualização no apontamento', 'Status do seu apontamento mudou.', ''],
        };

        $mail = (new MailMessage)
            ->subject("[Minutor] {$assunto} — {$projeto} ({$data})")
            ->greeting("Olá, {$notifiable->name}!")
            ->line($intro)
            ->line("**Projeto:** {$projeto}")
            ->line("**Cliente:** {$cliente}")
            ->line("**Data:** {$data}")
            ->line("**Horas:** {$horas}");

        if (!empty($this->reason)) {
            $mail->line("**Motivo informado pelo aprovador:**")
                 ->line($this->reason);
        }

        if ($cta) {
            $mail->line($cta);
        }

        $mail->action('Abrir Apontamentos', config('app.frontend_url', 'https://app.minutor.com.br') . '/timesheets')
             ->line('Em caso de dúvida, fale com seu coordenador.');

        return $mail;
    }
}
