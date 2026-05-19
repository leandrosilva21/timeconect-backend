<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Notifica executivo da conta + coordenadores + contatos do cliente quando
 * um contrato vira projeto (POST /contracts/{id}/generate-project).
 *
 * Síncrono (sem ShouldQueue) — evento raro, evita depender de queue-worker
 * rodando em homolog.
 */
class ProjectFromContractGeneratedNotification extends Notification
{
    public Contract $contract;
    public Project $project;

    public function __construct(Contract $contract, Project $project)
    {
        $this->contract = $contract;
        $this->project  = $project;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $c = $this->contract->loadMissing(['customer:id,name']);
        $codigo  = $c->code ?? '—';
        $projeto = $this->project->name ?? ($c->project_name ?? '—');
        $cliente = optional($c->customer)->name ?? '—';

        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');
        $cardUrl = "{$base}/projetos/{$this->project->id}";

        $isCliente = $notifiable instanceof AnonymousNotifiable;
        $recipientName = $isCliente
            ? 'contato do cliente'
            : ($notifiable->name ?? 'time interno');

        return (new MailMessage)
            ->subject("[Minutor] Projeto criado — {$codigo}")
            ->view('emails.contracts.project-generated', [
                'codigo'        => $codigo,
                'projeto'       => $projeto,
                'cliente'       => $cliente,
                'cardUrl'       => $cardUrl,
                'recipientName' => $recipientName,
                'isCliente'     => $isCliente,
            ]);
    }
}
