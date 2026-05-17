<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Aritmética de datas em dias úteis (skip sábado, domingo, feriados ativos).
 *
 * Fonte canônica de calendário para o cronograma operacional (ADR 0009 appendix).
 * Lista de feriados ativos é cacheada por request (singleton via app container).
 */
class BusinessCalendarService
{
    /** @var array<string, true> Set de datas YYYY-MM-DD em feriado ativo. */
    private array $holidaySet;

    public function __construct()
    {
        $this->holidaySet = Holiday::active()
            ->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();
    }

    /**
     * Verifica se a data é dia útil. Opts (default vazio = comportamento padrão):
     *   - allow_weekend (bool) → sábado/domingo viram úteis
     *   - allow_holiday (bool) → feriado vira útil
     */
    public function isBusinessDay(CarbonInterface $date, array $opts = []): bool
    {
        $allowWeekend = !empty($opts['allow_weekend']);
        $allowHoliday = !empty($opts['allow_holiday']);

        if ($date->isWeekend() && !$allowWeekend) {
            return false;
        }
        if (isset($this->holidaySet[$date->toDateString()]) && !$allowHoliday) {
            return false;
        }
        return true;
    }

    /**
     * Retorna a próxima data útil >= $date. Se $date já é útil, devolve $date.
     */
    public function nextBusinessDay(CarbonInterface $date, array $opts = []): Carbon
    {
        $d = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        while (!$this->isBusinessDay($d, $opts)) {
            $d->addDay();
        }
        return $d;
    }

    /**
     * Adiciona N dias úteis a partir de $start. Se $start não é dia útil,
     * o "dia 0" é o próximo dia útil.
     */
    public function addBusinessDays(CarbonInterface $start, int $days, array $opts = []): Carbon
    {
        $d = $this->nextBusinessDay($start, $opts);
        for ($i = 0; $i < $days; $i++) {
            $d->addDay();
            while (!$this->isBusinessDay($d, $opts)) {
                $d->addDay();
            }
        }
        return $d;
    }

    /**
     * Conta dias úteis entre $start e $end (inclusivo). Skip sábado/domingo/feriado.
     * Retorna 0 se $end < $start. Retorna 1 se mesmo dia e dia útil.
     */
    public function businessDaysBetween(CarbonInterface $start, CarbonInterface $end, array $opts = []): int
    {
        $a = $start instanceof Carbon ? $start->copy()->startOfDay() : Carbon::parse($start)->startOfDay();
        $b = $end instanceof Carbon ? $end->copy()->startOfDay() : Carbon::parse($end)->startOfDay();
        if ($b->lt($a)) return 0;
        $count = 0;
        $cursor = $a->copy();
        while ($cursor->lte($b)) {
            if ($this->isBusinessDay($cursor, $opts)) $count++;
            $cursor->addDay();
        }
        return $count;
    }

    /**
     * Distribui $hours horas a partir de $start usando $dailyHours por dia útil.
     * Retorna o datetime do término (último dia útil consumido, no horário
     * em que a última fração de hora caiu).
     *
     * Exemplo: addBusinessHours(2026-09-01 09:00, 20, 8) → 2026-09-03 13:00
     * (8h em 01/09, 8h em 02/09, 4h em 03/09).
     *
     * Retorna $start se hours <= 0.
     */
    public function addBusinessHours(CarbonInterface $start, float $hours, float $dailyHours = 8.0, array $opts = []): Carbon
    {
        $cursor = $start instanceof Carbon ? $start->copy() : Carbon::parse($start);
        if ($hours <= 0 || $dailyHours <= 0) {
            return $cursor;
        }

        $remaining = $hours;
        $cursor = $this->nextBusinessDay($cursor, $opts);

        while ($remaining > $dailyHours) {
            $remaining -= $dailyHours;
            $cursor->addDay();
            while (!$this->isBusinessDay($cursor, $opts)) {
                $cursor->addDay();
            }
        }

        return $cursor;
    }
}
