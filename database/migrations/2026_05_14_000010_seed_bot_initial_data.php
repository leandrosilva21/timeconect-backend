<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── AI Providers ─────────────────────────────────────────────
        DB::table('ai_providers')->insertOrIgnore([
            [
                'slug'          => 'anthropic',
                'name'          => 'Anthropic Claude',
                'api_key_env'   => 'ANTHROPIC_API_KEY',
                'base_url'      => 'https://api.anthropic.com/v1',
                'default_model' => 'claude-sonnet-4-6',
                'enabled'       => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'openai',
                'name'          => 'OpenAI',
                'api_key_env'   => 'OPENAI_API_KEY',
                'base_url'      => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o-mini',
                'enabled'       => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'slug'          => 'gemini',
                'name'          => 'Google Gemini',
                'api_key_env'   => 'GEMINI_API_KEY',
                'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
                'default_model' => 'gemini-1.5-flash',
                'enabled'       => false,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ]);

        $anthropicId = DB::table('ai_providers')->where('slug', 'anthropic')->value('id');

        // ── BOT Config (singleton) ───────────────────────────────────
        if (! DB::table('bot_configs')->exists()) {
            DB::table('bot_configs')->insert([
                'active_provider_id'         => $anthropicId,
                'default_model'              => 'claude-sonnet-4-6',
                'temperature'                => 0.30,
                'frequency_cron'             => '0 6 * * 1',
                'default_severity_threshold' => 'high',
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }

        // ── BOT Agents (6 agents do n8n original) ────────────────────
        $commonStructure = <<<TEXT
Você é um analista sênior de Customer Success da ERPSERV.
Responda SEMPRE em 4 seções (máx. 3 bullets cada), em português brasileiro:
- **Diagnóstico**: resumo da situação.
- **Evidência**: dados que comprovam.
- **Impacto**: consequência para o cliente.
- **Direção**: ações sugeridas.

Não invente dados que não estejam no contexto JSON.
Não use texto longo. Seja conciso e acionável.
TEXT;

        $agents = [
            [
                'slug' => 'account_intelligence',
                'name' => 'Account Intelligence',
                'role_description' => 'Diagnóstico base da saúde da conta — roda sempre.',
                'priority' => 10,
                'trigger_conditions' => json_encode(['always' => true]),
                'min_severity' => 'info',
                'system_prompt' => $commonStructure . "\n\nAtue como **Account Intelligence**. Foque em: saúde geral, sinais relevantes, contexto resumido.",
            ],
            [
                'slug' => 'support_intelligence',
                'name' => 'Support Intelligence',
                'role_description' => 'Analisa tickets e gargalos de suporte.',
                'priority' => 20,
                'trigger_conditions' => json_encode(['tickets_gt' => 0]),
                'min_severity' => 'low',
                'system_prompt' => $commonStructure . "\n\nAtue como **Support Intelligence**. Foque em: padrão dos chamados, gargalos, áreas mais afetadas.",
            ],
            [
                'slug' => 'client_growth',
                'name' => 'Client Growth',
                'role_description' => 'Sugere melhorias operacionais e crescimento orgânico.',
                'priority' => 30,
                'trigger_conditions' => json_encode(['classification' => ['EXPANSAO']]),
                'min_severity' => 'info',
                'system_prompt' => $commonStructure . "\n\nAtue como **Client Growth**. Foque em: ganho de eficiência, próximos passos operacionais.",
            ],
            [
                'slug' => 'revenue_radar',
                'name' => 'Revenue Radar',
                'role_description' => 'Identifica oportunidades de novos projetos / receita.',
                'priority' => 31,
                'trigger_conditions' => json_encode(['classification' => ['EXPANSAO']]),
                'min_severity' => 'info',
                'system_prompt' => $commonStructure . "\n\nAtue como **Revenue Radar**. Foque em: potencial de expansão, ROI estimado, abordagem comercial.",
            ],
            [
                'slug' => 'retention_agent',
                'name' => 'Retention Agent',
                'role_description' => 'Detecta risco de churn e propõe contenção.',
                'priority' => 40,
                'trigger_conditions' => json_encode(['classification' => ['RISCO']]),
                'min_severity' => 'high',
                'system_prompt' => $commonStructure . "\n\nAtue como **Retention Agent**. Foque em: alertas de churn, perda potencial, plano de contenção.",
            ],
            [
                'slug' => 'training_agent',
                'name' => 'Training Agent',
                'role_description' => 'Identifica defasagem técnica e sugere treinamento.',
                'priority' => 50,
                'trigger_conditions' => json_encode(['score_gt' => 3]),
                'min_severity' => 'medium',
                'system_prompt' => $commonStructure . "\n\nAtue como **Training Agent**. Foque em: erros recorrentes, subutilização do ERP, plano de treinamento.",
            ],
        ];

        foreach ($agents as $a) {
            DB::table('bot_agents')->insertOrIgnore([
                'slug'               => $a['slug'],
                'name'               => $a['name'],
                'role_description'   => $a['role_description'],
                'system_prompt'      => $a['system_prompt'],
                'active'             => true,
                'priority'           => $a['priority'],
                'min_severity'       => $a['min_severity'],
                'trigger_conditions' => $a['trigger_conditions'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        // ── BOT Skills (regras de threshold do n8n original) ─────────
        $skills = [
            [
                'slug' => 'tickets_critical',
                'name' => 'Tickets críticos',
                'description' => '> 15 tickets em 90 dias = crítico',
                'rule_type' => 'threshold',
                'config' => json_encode(['metric' => 'tickets_90d', 'operator' => '>', 'value' => 15]),
                'severity' => 'critical',
            ],
            [
                'slug' => 'tickets_high',
                'name' => 'Aumento de tickets',
                'description' => '> 10 tickets em 90 dias = alto',
                'rule_type' => 'threshold',
                'config' => json_encode(['metric' => 'tickets_90d', 'operator' => '>', 'value' => 10]),
                'severity' => 'high',
            ],
            [
                'slug' => 'hours_overrun',
                'name' => 'Estouro de horas',
                'description' => 'Saldo de horas < 0 = atenção a estouro',
                'rule_type' => 'threshold',
                'config' => json_encode(['metric' => 'hours_balance', 'operator' => '<', 'value' => 0]),
                'severity' => 'high',
            ],
            [
                'slug' => 'expansion_opportunity',
                'name' => 'Oportunidade de expansão',
                'description' => 'Zero tickets + saldo > 50h = oportunidade',
                'rule_type' => 'threshold',
                'config' => json_encode([
                    'metric' => 'tickets_90d', 'operator' => '=', 'value' => 0,
                    'and' => ['metric' => 'hours_balance', 'operator' => '>', 'value' => 50],
                ]),
                'severity' => 'info',
            ],
        ];

        foreach ($skills as $s) {
            DB::table('bot_skills')->insertOrIgnore(array_merge($s, [
                'active'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // ── Notification Rule default ────────────────────────────────
        DB::table('bot_notification_rules')->insertOrIgnore([
            'name'          => 'Inbox para admins quando severity ≥ high',
            'trigger_event' => 'App\\Events\\OperationalFeedCreated',
            'severity_min'  => 'high',
            'target_type'   => 'all_admins',
            'target_value'  => null,
            'channel'       => 'inbox',
            'active'        => true,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('bot_notification_rules')->truncate();
        DB::table('bot_skills')->truncate();
        DB::table('bot_agents')->truncate();
        DB::table('bot_configs')->truncate();
        DB::table('ai_providers')->truncate();
    }
};
