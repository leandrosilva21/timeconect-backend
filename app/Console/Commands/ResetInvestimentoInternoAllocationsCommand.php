<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetInvestimentoInternoAllocationsCommand extends Command
{
    protected $signature   = 'investimento-interno:reset-allocations {--force : Forçar mesmo que hoje não seja o 2º dia útil}';
    protected $description = 'Desaloca todos os consultores dos projetos de Investimento Interno no 2º dia útil do mês';

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

        $projects = Project::where('is_investimento_comercial', true)->get();
        $totalRemoved = 0;
        $projectsAffected = 0;

        foreach ($projects as $project) {
            $count = $project->consultants()->count();
            if ($count > 0) {
                $project->consultants()->detach();
                $totalRemoved += $count;
                $projectsAffected++;
            }
        }

        $msg = "Reset Investimento Interno: {$totalRemoved} alocações removidas em {$projectsAffected} projeto(s).";
        $this->info($msg);
        Log::info("investimento-interno:reset-allocations — {$msg}");

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
