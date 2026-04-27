<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanHomologData extends Command
{
    protected $signature   = 'db:clean-homolog {--force : Pula confirmação}';
    protected $description = 'Limpa todos os dados de movimentação do ambiente de homologação';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('Isso apagará TODOS os dados de movimentação. Confirmar?')) {
                $this->info('Cancelado.');
                return 0;
            }
        }

        $this->info('Limpando dados...');

        DB::statement('SET session_replication_role = replica;'); // desativa FK checks no PostgreSQL

        $tables = [
            // Tokens de sessão
            'personal_access_tokens',

            // Mensagens e leituras
            'contract_message_reads',
            'contract_message_attachments',
            'contract_messages',
            'contract_request_message_attachments',
            'contract_request_messages',
            'project_message_reads',
            'project_message_mentions',
            'project_message_attachments',
            'project_messages',

            // Fechamentos
            'fechamento_parceiros',
            'fechamento_clientes',
            'fechamento_administrativos',

            // Banco de horas
            'consultant_hour_bank_closings',
            'user_hourly_rate_logs',

            // Timesheets
            'timesheet_reversals',
            'timesheets',

            // Despesas
            'expense_reversals',
            'expenses',

            // Contratos e requisições
            'contract_request_kanban_logs',
            'contract_attachments',
            'contract_contacts',
            'contracts',
            'contract_requests',

            // Projetos
            'project_coordinator_percentage_logs',
            'project_coordinators',
            'project_consultants',
            'project_consultant_groups',
            'project_kanban_logs',
            'project_change_logs',
            'project_sold_hours_history',
            'project_sequences',
            'project_contacts',
            'project_attachments',
            'hour_contributions',
            'custom_field_values',
            'movidesk_tickets',
            'projects',
        ];

        foreach ($tables as $table) {
            try {
                DB::statement("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
                $this->line("  ✓ {$table}");
            } catch (\Exception $e) {
                $this->warn("  ⚠ {$table}: " . $e->getMessage());
            }
        }

        DB::statement('SET session_replication_role = DEFAULT;'); // reativa FK checks

        $this->info('Limpeza concluída.');
        return 0;
    }
}
