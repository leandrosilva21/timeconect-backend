<?php

namespace App\Console\Commands;

use App\Models\MovideskTicket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MovideskDebugResponsavelCommand extends Command
{
    protected $signature   = 'movidesk:debug-responsavel';
    protected $description = 'Lista emails do responsavel no Movidesk e verifica vínculo com usuários do Time Conect';

    public function handle(): int
    {
        // Pega todos os emails distintos do campo responsavel JSON
        $rows = MovideskTicket::whereNotNull('responsavel')
            ->selectRaw("responsavel->>'email' as email, COUNT(*) as tickets, SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as vinculados")
            ->groupByRaw("responsavel->>'email'")
            ->orderByDesc('tickets')
            ->get();

        $userEmails = User::pluck('email')->map(fn($e) => strtolower($e))->toArray();

        $this->line('');
        $this->line('=== RESPONSÁVEIS NO MOVIDESK ===');
        $this->line('');

        $headers = ['Email no Movidesk', 'Tickets', 'Já vinculados', 'Usuário no Time Conect?'];
        $tableData = $rows->map(function ($row) use ($userEmails) {
            $email = $row->email ?? '(vazio)';
            $found = in_array(strtolower($email), $userEmails) ? '✓ SIM' : '✗ NÃO';
            return [$email, $row->tickets, $row->vinculados, $found];
        })->toArray();

        $this->table($headers, $tableData);

        $semEmail = MovideskTicket::whereNotNull('responsavel')
            ->whereRaw("responsavel->>'email' IS NULL OR responsavel->>'email' = ''")
            ->count();

        if ($semEmail > 0) {
            $this->line('');
            $this->warn("{$semEmail} ticket(s) com responsavel sem email.");
        }

        $semResponsavel = MovideskTicket::whereNull('responsavel')->count();
        $this->line('');
        $this->line("{$semResponsavel} ticket(s) sem responsavel algum.");
        $this->line('');

        return self::SUCCESS;
    }
}
