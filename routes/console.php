<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanupContractEventsJob;
use App\Jobs\NormalizeContractStateJob;
use App\Jobs\UpdateAccumulatedSoldHoursJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agendar atualização de horas acumuladas todo dia 1 de cada mês
// Múltiplos horários para aumentar chances de execução mesmo se servidor estiver offline
Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '00:00')
  ->name('update-accumulated-sold-hours-00:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 1 (00:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '06:00')
  ->name('update-accumulated-sold-hours-06:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 2 (06:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '12:00')
  ->name('update-accumulated-sold-hours-12:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 3 (12:00)');

Schedule::call(function () {
    UpdateAccumulatedSoldHoursJob::dispatch();
})->monthlyOn(1, '18:00')
  ->name('update-accumulated-sold-hours-18:00')
  ->description('Atualiza accumulated_sold_hours - Tentativa 4 (18:00)');

// Comando de verificação que roda diariamente para garantir execução mensal
Schedule::command('projects:ensure-monthly-update')
  ->dailyAt('02:00')
  ->name('ensure-monthly-accumulated-hours-update')
  ->description('Verifica se accumulated_sold_hours foi atualizado no mês atual');

// Rede de segurança pro reset de conflitos órfãos (observer já cobre saves/deletes
// via Eloquent; este sweep cobre mudanças via SQL bruto e legado pré-observer).
Schedule::command('timesheets:resolve-stale-conflicts')
  ->cron('*/10 * * * *')
  ->name('timesheets-resolve-stale-conflicts')
  ->description('Reverte apontamentos conflicted sem sobreposição para pending')
  ->withoutOverlapping(10)
  ->runInBackground();

// Sync de apontamentos Movidesk (fallback do webhook — garante que nenhum apontamento seja perdido)
Schedule::command('movidesk:sync')
  ->cron('*/5 * * * *')
  ->name('movidesk-sync')
  ->description('Sincroniza apontamentos do Movidesk via API')
  ->withoutOverlapping(10)
  ->runInBackground();

// Slow-lane: tickets que falharam no sync principal (timeout 5s) são reprocessados
// aqui de hora em hora via fetchTicketLight (timeout 30s, $expand mínimo).
Schedule::command('movidesk:retry-problem-tickets')
  ->hourly()
  ->name('movidesk-retry-problem-tickets')
  ->description('Reprocessa tickets do Movidesk que falharam na sync principal')
  ->withoutOverlapping(120)
  ->runInBackground();

// Sync do Portal de Sustentação (tickets dos últimos 90 dias com campos SLA)
try { $portalSyncInterval = (int) \App\Models\SystemSetting::get('movidesk_portal_sync_interval_minutes', 30); }
catch (\Exception $e) { $portalSyncInterval = 30; }
Schedule::command('movidesk:portal-sync')
  ->cron("*/{$portalSyncInterval} * * * *")
  ->name('movidesk-portal-sync')
  ->description('Sincroniza tickets do Movidesk para o Portal de Sustentação')
  ->withoutOverlapping(60)
  ->runInBackground();

// Sync de organizações do Movidesk — popula CNPJ e customer_id nos tickets
try { $syncOrgsInterval = (int) \App\Models\SystemSetting::get('movidesk_sync_orgs_interval_minutes', 30); }
catch (\Exception $e) { $syncOrgsInterval = 30; }
Schedule::command('movidesk:sync-orgs')
  ->cron("*/{$syncOrgsInterval} * * * *")
  ->name('movidesk-sync-orgs')
  ->description('Sincroniza organizações do Movidesk e popula CNPJs nos tickets')
  ->withoutOverlapping(60)
  ->runInBackground();

// Normalização de snapshots de contratos — detecta e corrige divergências
Schedule::job(new NormalizeContractStateJob)
  ->cron('*/30 * * * *')
  ->name('normalize-contract-state')
  ->description('Fallback: detecta e corrige inconsistências nos snapshots de contratos')
  ->withoutOverlapping();

// Fechamento automático — roda diariamente às 06:00 e só age no 2º dia útil
Schedule::command('fechamento:auto-fechar')
  ->dailyAt('06:00')
  ->name('fechamento-auto-fechar')
  ->description('Fecha a competência do mês anterior no 2º dia útil do mês atual')
  ->withoutOverlapping();

// Reset das alocações de Investimento Interno — roda diariamente às 00:05
// e só age no 2º dia útil. Alocações feitas após esse horário no mesmo dia
// são preservadas.
Schedule::command('investimento-interno:reset-allocations')
  ->dailyAt('00:05')
  ->name('investimento-interno-reset-allocations')
  ->description('Desaloca consultores dos projetos de Investimento Interno no 2º dia útil')
  ->withoutOverlapping();

// Limpeza de eventos antigos — mantém histórico dos últimos 180 dias
Schedule::job(new CleanupContractEventsJob)
  ->weekly()
  ->name('cleanup-contract-events')
  ->description('Remove eventos de contrato com mais de 180 dias')
  ->withoutOverlapping();
