<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportProjectsSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // Resolve IDs dinamicamente (não hardcoded)
        // ------------------------------------------------------------------
        $serviceTypeId = DB::table('service_types')->where('name', 'Projeto')->value('id');
        if (!$serviceTypeId) {
            $this->command->error('service_type "Projeto" não encontrado!');
            return;
        }

        $coordinators = DB::table('users')
            ->whereIn('name', ['Aline Toyoda', 'Juliana Parreja', 'Nao definido'])
            ->pluck('id', 'name');

        $contractTypes = DB::table('contract_types')->pluck('id', 'name');

        // Mapa de tipo_faturamento → contract_type.name
        $tipoFatMap = [
            'Por hora (Banco de Horas Fixo)'    => 'Banco de Horas Fixo',
            'Por hora (Banco de Horas Mensais)' => 'Banco de Horas Mensal',
            'Por hora (Banco de Horas Mensal)'  => 'Banco de Horas Mensal',
            'Por serviço'                        => 'Fechado',
            'Saas'                               => 'SaaS',
        ];

        // Mapa de status planilha → enum DB
        $statusMap = [
            'Em andamento' => 'started',
            'Pausado'       => 'paused',
        ];

        // ------------------------------------------------------------------
        // Dados da planilha (gerado automaticamente)
        // ------------------------------------------------------------------
        $rows = [
            // [name, code, status_raw, cnpj, customer_name, sold_hours, hourly_rate,
            //  aporte_hours, initial_cost, initial_hours_balance, tipo_faturamento, coordinator_name]
            ['[PROJETOS] BANCO DE HORAS 2025',                             'BLC011-24-PRJ', 'Em andamento', '08065022000141', 'BLASCOR',        0,       0,      0,    0,        0,          'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['IMPLANTAÇÃO E REVITALIZACAO DOS MODULOS PROTHEUS',           'ESP002-25',     'Em andamento', '06982873000123', 'EUREKA',          570,     148,    0,    19078.13, 264.75,     'Por hora (Banco de Horas Fixo)',    'Juliana Parreja'],
            ['IMPORTAÇÃO PEDIDO DE VENDA VIA PLANILHA',                    'ESP006-25',     'Em andamento', '06982873000123', 'EUREKA',          100,     0,      0,    4125,     45,         'Por serviço',                       'Nao definido'],
            ['SANEAMENTO CADASTRO DE FORNECEDORES',                        'ESP007-25',     'Em andamento', '06982873000123', 'EUREKA',          24,      0,      0,    1875,     -1,         'Por serviço',                       'Nao definido'],
            ['INTEGRAÇÃO LECOM X PROTHEUS - FORNECEDORES',                 'ERF015-25',     'Em andamento', '04329668000138', 'EUROFINS',        153,     170,    0,    9920,     28.94,      'Por hora (Banco de Horas Fixo)',    'Aline Toyoda'],
            ['IMPLANTAÇÃO TOTVS PROTHEUS',                                 'GNX001-26',     'Em andamento', '27157827000160', 'GNX',             1959,    158,    0,    0,        1958.6,     'Por hora (Banco de Horas Fixo)',    'Juliana Parreja'],
            ['CONFIGURADOR DE TRIBUTOS E CONTABILIDADE',                   'ESP003-25',     'Em andamento', '06982873000123', 'GRUPO EUREKA',    310,     148,    0,    0,        310,        'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['[PROJETOS] BANCO DE HORAS MENSAL',                           'ESP005-25',     'Em andamento', '06982873000123', 'GRUPO EUREKA',    32,      172,    48,   0,        80,         'Por hora (Banco de Horas Mensais)', 'Nao definido'],
            ['ROLLOUT IMPLANTAÇÃO MANUTENÇÃO DE ATIVOS',                   'GTF002-25',     'Em andamento', '85070068003387', 'GTF',             2688,    0,      0,    0,        2688,       'Por serviço',                       'Aline Toyoda'],
            ['PROCESSO DE TRANSFERENCIA MATERIAS',                         'HTM007-24',     'Em andamento', '03271206000144', 'HTM',             320,     153.09, 0,    34665,    -257.75,    'Por serviço',                       'Aline Toyoda'],
            ['IMPLANTAÇÃO CONFIGURADOR DE TRIBUTOS',                       'HTM013-25',     'Em andamento', '03271206000144', 'HTM',             0,       0,      0,    31906.25, -510.5,     'Por serviço',                       'Juliana Parreja'],
            ['CONFIGURADOR DE TRIBUTOS',                                   'HUB009-26',     'Em andamento', '16576763000115', 'HUB ACCOUNTING',  0,       0,      0,    3765.63,  -60.25,     'Por serviço',                       'Juliana Parreja'],
            ['REVITALIZAÇÃO CONTÁBIL',                                     'HYD003-25',     'Em andamento', '03715168000171', 'HYDRAFORCE',      160,     135,    0,    14720,    -24,        'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['RELATORIO CADASTRO RH',                                      'HYD010-25',     'Em andamento', '03715168000171', 'HYDRAFORCE',      64,      135,    0,    0,        64,         'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['IMPLANTAÇÃO FCI',                                            'HYD014-26',     'Em andamento', '03715168000171', 'HYDRAFORCE',      78,      0,      0,    0,        78,         'Por hora (Banco de Horas Fixo)',    'Juliana Parreja'],
            ['ATUALIZAÇÃO DE RELEASE 12.1.2410',                          'INV012-25',     'Em andamento', '68372101000127', 'INVEL',           70,      0,      0,    5600,     0,          'Por serviço',                       'Nao definido'],
            ['REVITALIZAÇÃO DOS MODULOS PROTHEUS BANCO DE HORAS',         'INV003-25',     'Em andamento', '46946192000124', 'INVENTA',         1040,    150,    0,    51263.33, 14.73,      'Por hora (Banco de Horas Fixo)',    'Aline Toyoda'],
            ['GCT',                                                        'KNT005-24',     'Em andamento', '08911199000111', 'KONECTA',         0,       0,      0,    2880,     -32,        'Por hora (Banco de Horas Fixo)',    'Juliana Parreja'],
            ['[PROJETOS] APONTAMENTOS DIVERSOS',                          'MBJ006-26',     'Em andamento', '07497572000177', 'MINAS BOJO',      0,       0,      0,    53002.5,  -1241.83,   'Saas',                              'Aline Toyoda'],
            ['CONSULTORIA RM BANCO DE HRS FIXO',                          'NET001-24',     'Em andamento', '31243041000132', 'NET MANAGER',     18,      134.4,  0,    800,      10,         'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['MELHORIAS INTEGRAÇÃO PIPEDRIVE',                            'NSA003-26',     'Em andamento', '20041743000117', 'NLMK',            16,      195,    0,    0,        16,         'Por hora (Banco de Horas Fixo)',    'Aline Toyoda'],
            ['CONFIGURADOR DE TRIBUTOS',                                   'QEP001-25',     'Em andamento', '08666285000106', 'QAIR BRASIL',     32,      0,      0,    1437.49,  3.25,       'Por hora (Banco de Horas Fixo)',    'Aline Toyoda'],
            ['IMPLANTAÇÃO CNAB ITAU',                                      'CFP002-25',     'Em andamento', '29229337846',   'SERRA FORMOSA',   80,      0,      0,    2400,     50,         'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['BANCO DE HORAS SUPORTE',                                     'CFP004-25',     'Em andamento', '29229337846',   'SERRA FORMOSA',   10,      0,      0,    1050,     -8.5,       'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['DESENVOLVIMENTO ETIQUETA',                                   'SLV008-24',     'Em andamento', '60433778000116', 'SOLVENTEX',       200,     0,      0,    16000,    0,          'Por hora (Banco de Horas Fixo)',    'Nao definido'],
            ['FLUIG - INTEGRAÇÃO PROTHEUS - PRODUTOS',                    'UNI002-25',     'Em andamento', '15181688000120', 'UNIMETAL',        0,       0,      0,    13483.5,  -151.5,     'Por serviço',                       'Juliana Parreja'],
            ['FLUIG - TARGET X PROTHEUS',                                  'UNI003-25',     'Em andamento', '15181688000120', 'UNIMETAL',        0,       0,      0,    13661.5,  -153.5,     'Por serviço',                       'Nao definido'],
            ['IMPLANTAÇÃO GESTÃO DE FROTAS E MNT',                        'UNI005-25',     'Em andamento', '15181688000120', 'UNIMETAL',        214,     0,      0,    8840,     110,        'Por serviço',                       'Juliana Parreja'],
            ['BANCO DE HORAS PROJETO PROCESSO PCP',                       'UNI008-26',     'Em andamento', '15181688000120', 'UNIMETAL',        365,     0,      0,    3990,     308,        'Por hora (Banco de Horas Fixo)',    'Juliana Parreja'],
            ['CLOUD PROTHEUS',                                             'WLC001-25',     'Em andamento', '16628359000148', 'WILSON COUROS',   0,       0,      0,    7636.72,  -143.75,    'Por serviço',                       'Nao definido'],
            ['ESTRUTURACAO BI VS03',                                       'ZBE001-25',     'Pausado',       '24025216000170', 'ZEG BIOGAS',      160,     200,    0,    12960,    -2,         'Por serviço',                       'Aline Toyoda'],
        ];

        $startDate = '2026-01-01';
        $now = Carbon::now();
        $imported = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            [$name, $code, $statusRaw, $cnpj, $customerName,
             $soldHours, $hourlyRate, $aporteHours, $initialCost,
             $initialBalance, $tipoFat, $coordinatorName] = $row;

            // Verifica se já existe
            if (DB::table('projects')->where('code', $code)->exists()) {
                $this->command->warn("Pulando $code — já existe.");
                $skipped++;
                continue;
            }

            // Resolve customer_id por CNPJ limpo, com fallback por nome
            $cnpjClean = preg_replace('/[^0-9]/', '', $cnpj);
            $customerId = DB::table('customers')
                ->whereRaw("regexp_replace(cgc, '[^0-9]', '', 'g') = ?", [$cnpjClean])
                ->whereNull('deleted_at')
                ->value('id');

            if (!$customerId) {
                // Fallback: busca pelo nome do cliente
                $customerId = DB::table('customers')
                    ->whereRaw('UPPER(name) = ?', [strtoupper($customerName)])
                    ->whereNull('deleted_at')
                    ->value('id');
            }

            if (!$customerId) {
                $this->command->error("Cliente NÃO ENCONTRADO: $customerName (CNPJ $cnpj) — pulando $code.");
                $skipped++;
                continue;
            }

            $status = $statusMap[$statusRaw] ?? 'started';
            $ctName = $tipoFatMap[$tipoFat] ?? 'Fechado';
            $contractTypeId = $contractTypes[$ctName] ?? $contractTypes['Fechado'];

            // INSERT project
            $projectId = DB::table('projects')->insertGetId([
                'name'                  => $name,
                'code'                  => $code,
                'status'                => $status,
                'customer_id'           => $customerId,
                'service_type_id'       => $serviceTypeId,
                'contract_type_id'      => $contractTypeId,
                'sold_hours'            => $soldHours > 0 ? (int) round($soldHours) : null,
                'hourly_rate'           => $hourlyRate > 0 ? $hourlyRate : null,
                'hour_contribution'     => $aporteHours > 0 ? $aporteHours : null,
                'initial_cost'          => $initialCost > 0 ? $initialCost : null,
                'initial_hours_balance' => $initialBalance != 0 ? $initialBalance : null,
                'start_date'            => $startDate,
                'tipo_faturamento'      => $tipoFat,
                'is_manual_code'        => true,
                'allow_manual_timesheets' => true,
                'unlimited_expense'     => false,
                'allow_negative_balance' => false,
                'cobra_despesa_cliente' => false,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            // Coordinator → project_coordinators
            $coordinatorId = $coordinators[$coordinatorName] ?? null;
            if ($coordinatorId) {
                DB::table('project_coordinators')->insert([
                    'project_id' => $projectId,
                    'user_id'    => $coordinatorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Aporte → hour_contributions (data = start_date conforme solicitado)
            if ($aporteHours > 0) {
                DB::table('hour_contributions')->insert([
                    'project_id'      => $projectId,
                    'contributed_hours' => $aporteHours,
                    'hourly_rate'     => $hourlyRate > 0 ? $hourlyRate : 0,
                    'description'     => 'Aporte inicial',
                    'contributed_at'  => $startDate . ' 00:00:00',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            $this->command->info("✓ $code — $name");
            $imported++;
        }

        $this->command->line("---");
        $this->command->info("Importados: $imported | Pulados: $skipped");
    }
}
