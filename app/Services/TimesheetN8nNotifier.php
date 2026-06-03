<?php

namespace App\Services;

use App\Models\Timesheet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TimesheetN8nNotifier
{
    public function notify(Timesheet $timesheet, string $statusKey, ?string $reason = null): void
    {
        if (!config('services.n8n.timesheet_webhook_enabled')) {
            return;
        }

        if (app()->environment('testing')) {
            return;
        }

        $url = config('services.n8n.timesheet_webhook_url');
        if (!$url) {
            return;
        }

        $timesheet->loadMissing(['user', 'project']);

        $email = optional($timesheet->user)->email;
        if (!$email) {
            return;
        }

        $minutes = (int) ($timesheet->effort_minutes ?? 0);
        $horas   = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        $payload = [
            'status'         => $statusKey,
            'usuario'        => optional($timesheet->user)->name ?? '',
            'email'          => $email,
            'apontamento_id' => (string) $timesheet->id,
            'projeto'        => optional($timesheet->project)->name ?? '—',
            'data'           => optional($timesheet->date)->format('d/m/Y'),
            'horas'          => $horas,
            'motivo'         => (string) ($reason ?? ''),
        ];

        try {
            Http::timeout(5)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload)
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('TimesheetN8nNotifier: falha ao enviar webhook', [
                'timesheet_id' => $timesheet->id,
                'status'       => $statusKey,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
