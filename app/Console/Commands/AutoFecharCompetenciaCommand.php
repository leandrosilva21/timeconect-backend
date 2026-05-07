<?php

namespace App\Console\Commands;

use App\Http\Controllers\FechamentoController;
use App\Models\FechamentoAdministrativo;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoFecharCompetenciaCommand extends Command
{
    protected $signature   = 'fechamento:auto-fechar {--force : Forçar fechamento mesmo que hoje não seja o 2º dia útil}';
    protected $description = 'Fecha automaticamente a competência do mês anterior no 2º dia útil do mês atual';

    public function handle(): int
    {
        $hoje = Carbon::today();

        if (!$this->option('force')) {
            $segundoDiaUtil = $this->calcularSegundoDiaUtil($hoje->year, $hoje->month);

            if (!$hoje->isSameDay($segundoDiaUtil)) {
                $this->info("Hoje ({$hoje->toDateString()}) não é o 2º dia útil do mês ({$segundoDiaUtil->toDateString()}). Nenhuma ação.");
                return 0;
            }
        }

        $mesAnterior = $hoje->copy()->subMonth()->format('Y-m');

        $fechamento = FechamentoAdministrativo::where('year_month', $mesAnterior)->first();
        if ($fechamento?->isClosed()) {
            $this->info("Competência {$mesAnterior} já está fechada. Nenhuma ação.");
            return 0;
        }

        $this->info("Fechando competência {$mesAnterior} automaticamente...");

        try {
            app(FechamentoController::class)->fecharMes($mesAnterior, null, 'Fechamento automático — 2º dia útil do mês');
            $this->info("Competência {$mesAnterior} fechada com sucesso.");
            \Log::info("fechamento:auto-fechar — competência {$mesAnterior} fechada automaticamente.");
        } catch (\Throwable $e) {
            $this->error("Falha ao fechar competência {$mesAnterior}: {$e->getMessage()}");
            \Log::error("fechamento:auto-fechar — falha", ['error' => $e->getMessage(), 'yearMonth' => $mesAnterior]);
            return 1;
        }

        return 0;
    }

    private function calcularSegundoDiaUtil(int $ano, int $mes): Carbon
    {
        $feriados = Holiday::whereYear('date', $ano)
            ->whereMonth('date', $mes)
            ->where('active', true)
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $dia      = Carbon::create($ano, $mes, 1);
        $contagem = 0;

        while (true) {
            if (!$dia->isWeekend() && !in_array($dia->toDateString(), $feriados)) {
                $contagem++;
                if ($contagem === 2) {
                    return $dia->copy();
                }
            }
            $dia->addDay();
        }
    }
}
