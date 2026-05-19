<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Notifica executivo da conta + coordenadores + contatos do cliente quando
 * um contrato vira projeto (POST /contracts/{id}/generate-project).
 */
class ProjectFromContractGeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $url  = "{$base}/projetos/{$this->project->id}";

        // AnonymousNotifiable (contato do cliente) não tem `name` — usa "Olá!" genérico.
        $greeting = $notifiable instanceof AnonymousNotifiable
            ? 'Olá!'
            : "Olá, {$notifiable->name}!";

        return (new MailMessage)
            ->subject("[Minutor] Projeto criado — {$codigo}")
            ->greeting($greeting)
            ->line("O contrato {$codigo} foi liberado e gerou um novo projeto.")
            ->line("**Projeto:** {$projeto}")
            ->line("**Cliente:** {$cliente}")
            ->action('Abrir Projeto', $url)
            ->line('Esta é uma notificação automática — não responda.');
    }
}
