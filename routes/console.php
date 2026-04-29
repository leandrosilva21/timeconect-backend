<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
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

// Sync de apontamentos Movidesk (fallback do webhook — garante que nenhum apontamento seja perdido)
Schedule::command('movidesk:sync')
  ->cron('*/5 * * * *')
  ->name('movidesk-sync')
  ->description('Sincroniza apontamentos do Movidesk via API')
  ->withoutOverlapping()
  ->runInBackground();

// Sync do Portal de Sustentação (tickets dos últimos 90 dias com campos SLA)
Schedule::command('movidesk:portal-sync')
  ->everyThirtyMinutes()
  ->name('movidesk-portal-sync')
  ->description('Sincroniza tickets do Movidesk para o Portal de Sustentação')
  ->withoutOverlapping()
  ->runInBackground();

// Sync de organizações do Movidesk — popula CNPJ e customer_id nos tickets
Schedule::command('movidesk:sync-orgs')
  ->everyThirtyMinutes()
  ->name('movidesk-sync-orgs')
  ->description('Sincroniza organizações do Movidesk e popula CNPJs nos tickets')
  ->withoutOverlapping()
  ->runInBackground();
