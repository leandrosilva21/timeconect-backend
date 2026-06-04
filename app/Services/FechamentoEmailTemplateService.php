<?php

namespace App\Services;

use App\Models\FechamentoEmailTemplate;
use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Resolve o modelo de e-mail ativo de um fechamento e substitui as variáveis.
 * Variáveis: {nome} {periodo} {valor} {data_nota} (esta só p/ PJ).
 */
class FechamentoEmailTemplateService
{
    /**
     * @return array{subject: string, body: string}|null  null = sem modelo ativo (usa o default do controller).
     */
    public function resolve(string $categoria, ?string $contractType, array $vars): ?array
    {
        $q = FechamentoEmailTemplate::where('categoria', $categoria)->where('active', true);
        if ($categoria === 'cliente') {
            $q->whereNull('contract_type');
        } else {
            if (!$contractType) {
                return null; // consultor/parceiro sem tipo de contrato → cai no default
            }
            $q->where('contract_type', $contractType);
        }
        $tpl = $q->orderByDesc('id')->first();
        if (!$tpl) {
            return null;
        }

        return [
            'subject' => $this->substitute((string) $tpl->subject, $vars),
            'body'    => $this->substitute((string) $tpl->body, $vars),
        ];
    }

    private function substitute(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /**
     * Data de envio de nota do PJ: dia 20 do mês SEGUINTE ao yearMonth do
     * fechamento; se cair em fim de semana ou feriado, recua p/ o dia útil anterior.
     */
    public function dataEnvioNotaPj(string $yearMonth): string
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $d = Carbon::create($y, $m, 1)->startOfMonth()->addMonth()->day(20);

        $guard = 0;
        while ($guard++ < 31 && ($d->isWeekend() || $this->isHoliday($d))) {
            $d->subDay();
        }

        return $d->format('d/m/Y');
    }

    private function isHoliday(Carbon $d): bool
    {
        return Holiday::where('active', true)
            ->whereDate('date', $d->toDateString())
            ->exists();
    }
}
