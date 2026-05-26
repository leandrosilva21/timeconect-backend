<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migra os 6 sócios (antes hardcoded em FolhaPagamentoController::SOCIOS) para
 * linhas manuais persistidas em `fechamento_folha`. Como agora os sócios são
 * "linhas de inclusão manual" (por mês), semeia o mês corrente para que não
 * sumam da folha após a remoção do const. firstOrCreate por (year_month,
 * socio_key): preserva registros já editados; cria apenas os faltantes.
 * Identidade (cpf/nome/matrícula/status) fica na própria linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        $month  = '2026-05';
        $socios = [
            ['key' => 'ricardo_silva',      'nome' => 'RICARDO DE OLIVEIRA SILVA',           'cpf' => '313.017.868-61', 'matricula' => '46761', 'status' => 'Contratado', 'hm' => 'Mensalista'],
            ['key' => 'caio_maior',         'nome' => 'CAIO MAIOR GARCIA',                   'cpf' => '370.373.308-09', 'matricula' => '16383', 'status' => 'Contratado', 'hm' => 'Horista'],
            ['key' => 'ricardo_badawi',     'nome' => 'RICARDO BADAWI SANTOS',               'cpf' => '358.075.828-45', 'matricula' => '29653', 'status' => 'Contratado', 'hm' => 'Horista'],
            ['key' => 'leandro_silva',      'nome' => 'LEANDRO SANTOS E SILVA',              'cpf' => '328.265.748-09', 'matricula' => '1968',  'status' => 'Contratado', 'hm' => 'Horista'],
            ['key' => 'guilherme_junior',   'nome' => 'GUILHERME MATIAS DE OLIVEIRA JUNIOR', 'cpf' => '422.075.628-08', 'matricula' => '38046', 'status' => 'Contratado', 'hm' => 'Horista'],
            ['key' => 'daniel_albuquerque', 'nome' => 'DANIEL OLIVEIRA DE ALBUQUERQUE',      'cpf' => '003.701.572-90', 'matricula' => '16408', 'status' => 'Contratado', 'hm' => 'Horista'],
        ];

        foreach ($socios as $s) {
            $exists = DB::table('fechamento_folha')
                ->where('year_month', $month)
                ->where('socio_key', $s['key'])
                ->exists();

            if ($exists) {
                continue; // preserva linha já existente/editada
            }

            DB::table('fechamento_folha')->insert([
                'year_month'         => $month,
                'socio_key'          => $s['key'],
                'cpf'                => $s['cpf'],
                'nome'               => $s['nome'],
                'matricula'          => $s['matricula'],
                'status'             => $s['status'],
                'horista_mensalista' => $s['hm'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op: não remove dados de folha (sócios podem ter sido editados manualmente).
    }
};
