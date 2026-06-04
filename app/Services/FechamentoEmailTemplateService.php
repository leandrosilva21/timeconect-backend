<?php

namespace App\Services;

use App\Models\FechamentoEmailTemplate;
use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Resolve o modelo de e-mail ativo de um fechamento e substitui as variáveis.
 * Variáveis: {nome} {periodo} {valor} {data} (e {data_nota} = alias de {data}).
 * A {data} usa o "dia do mês" (pay_day) do modelo, no MÊS SEGUINTE ao fechamento.
 */
class FechamentoEmailTemplateService
{
    /**
     * @return array{subject: string, body: string}|null  null = sem modelo ativo (usa o default do controller).
     */
    public function resolve(string $categoria, ?string $contractType, array $vars, ?string $yearMonth = null): ?array
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

        // {data}: dia do mês (pay_day; PJ sem pay_day herda o 20 legado) no mês seguinte.
        $payDay = $tpl->pay_day ?: ($contractType === 'pj' ? 20 : null);
        if ($payDay && $yearMonth) {
            $data = $this->dataDoDia((int) $payDay, $yearMonth);
            $vars['data'] = $data;
            $vars['data_nota'] = $data; // alias retrocompatível
        }

        return [
            'subject' => $this->substitute((string) $tpl->subject, $vars),
            'body'    => $this->substitute((string) $tpl->body, $vars),
        ];
    }

    /**
     * Dia {day} do mês SEGUINTE ao yearMonth; se cair em fim de semana ou feriado,
     * recua para o PRIMEIRO dia útil anterior.
     */
    public function dataDoDia(int $day, string $yearMonth): string
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        $base = Carbon::create($y, $m, 1)->startOfMonth()->addMonth();
        $day  = max(1, min($day, $base->daysInMonth)); // clamp (ex.: 31 em mês de 30)
        $d    = $base->day($day);

        $guard = 0;
        while ($guard++ < 31 && ($d->isWeekend() || $this->isHoliday($d))) {
            $d->subDay();
        }

        return $d->format('d/m/Y');
    }

    private function substitute(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /** Legado: PJ dia 20. Mantido p/ compatibilidade — usa o dia configurável. */
    public function dataEnvioNotaPj(string $yearMonth): string
    {
        return $this->dataDoDia(20, $yearMonth);
    }

    private function isHoliday(Carbon $d): bool
    {
        return Holiday::where('active', true)
            ->whereDate('date', $d->toDateString())
            ->exists();
    }
}
