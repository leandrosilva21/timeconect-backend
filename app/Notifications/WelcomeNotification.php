<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $temporaryPassword;

    /**
     * Create a new notification instance.
     */
    public function __construct($temporaryPassword = null)
    {
        $this->temporaryPassword = $temporaryPassword;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $mail = (new MailMessage)
            ->subject('Bem-vindo(a) ao ' . config('app.name'))
            ->view('emails.auth.welcome', [
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
            ]);

        $manual = $this->resolveManual($notifiable);
        if ($manual) {
            $mail->attach($manual['path'], [
                'as'   => $manual['name'],
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }

    private function resolveManual($user): ?array
    {
        if ($user->type !== 'consultor') {
            return null;
        }

        $map = [
            'horista'        => ['file' => 'manual-consultor-horista.pdf',      'name' => 'Manual Time Conect - Consultor Horista.pdf'],
            'banco_de_horas' => ['file' => 'manual-consultor-banco-horas.pdf',  'name' => 'Manual Time Conect - Consultor Banco de Horas.pdf'],
        ];

        $entry = $map[$user->consultant_type] ?? null;
        if (!$entry) {
            return null;
        }

        $path = resource_path('manuals/' . $entry['file']);
        if (!file_exists($path)) {
            return null;
        }

        return ['path' => $path, 'name' => $entry['name']];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
