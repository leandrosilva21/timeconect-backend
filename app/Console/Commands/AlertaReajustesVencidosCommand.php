<?php

namespace App\Console\Commands;

use App\Http\Controllers\ContractController;
use App\Mail\ReajustesPendentesMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Alerta de reajustes vencidos — enviado ao Financeiro no 1º DIA ÚTIL do mês.
 * Lista os contratos com reajuste VENCIDO (pendentes de aplicação). Não aplica
 * nada: só avisa. Use --force para disparar fora do 1º dia útil (teste/manual).
 */
class AlertaReajustesVencidosCommand extends Command
{
    protected $signature = 'reajustes:alerta-vencidos {--force : Envia mesmo fora do 1º dia útil}';

    protected $description = 'Avisa o Financeiro (1º dia útil do mês) sobre contratos com reajuste vencido';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->isPrimeiroDiaUtil()) {
            $this->info('Hoje não é o primeiro dia útil do mês — nada a enviar.');
            return self::SUCCESS;
        }

        $vencidos = app(ContractController::class)->reajustesData()
            ->where('status_reajuste', 'vencido')
            ->values();

        if ($vencidos->isEmpty()) {
            $this->info('Nenhum contrato com reajuste vencido. Nenhum e-mail enviado.');
            return self::SUCCESS;
        }

        $totalImpacto = round((float) $vencidos->sum('valor_estimado_reajuste'), 2);
        $referencia   = $this->mesAno(Carbon::now());
        $dashboardUrl = rtrim(env('APP_FRONTEND_URL', config('app.url', 'https://app.timeconect.com.br')), '/') . '/fechamento/reajustes';
        $to           = config('mail.reajustes_alerta_to', env('MAIL_REAJUSTES_ALERTA_TO', 'financeiro@erpserv.com.br'));

        $mail = new ReajustesPendentesMail($vencidos->all(), $totalImpacto, $referencia, $dashboardUrl);
        // Entrega via Microsoft Graph (mesmo canal do fechamento); fallback p/ mailer default.
        $graphFrom = config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, [$to], [], $mail->envelope()->subject, $mail->render());
        } else {
            Mail::to($to)->send($mail);
        }

        $this->info("Alerta enviado para {$to}: {$vencidos->count()} contrato(s) vencido(s), impacto R$ " . number_format($totalImpacto, 2, ',', '.') . '.');
        return self::SUCCESS;
    }

    /** Primeiro dia útil do mês (pula sábado/domingo). */
    private function isPrimeiroDiaUtil(): bool
    {
        $first = Carbon::now()->startOfMonth();
        while ($first->isWeekend()) {
            $first->addDay();
        }
        return Carbon::now()->startOfDay()->isSameDay($first);
    }

    private function mesAno(Carbon $d): string
    {
        $m = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'][$d->month - 1];
        return $m . '/' . $d->year;
    }
}
