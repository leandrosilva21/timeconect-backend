<?php

namespace App\Http\Controllers;

use App\Models\AiProviderConfig;
use App\Models\BotAgent;
use App\Models\BotConfig;
use App\Models\BotNotificationRule;
use App\Models\BotSkill;
use App\Models\OperationalFeed;
use App\Services\Bot\NotificationEngine;
use App\Services\Bot\NotificationRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD único para configurações do BOT Minutor:
 * - GET    /api/v1/bot/config             → general
 * - PUT    /api/v1/bot/config             → update general
 * - GET    /api/v1/bot/providers          → list AI providers
 * - PUT    /api/v1/bot/providers/{id}     → update provider
 * - GET    /api/v1/bot/agents             → list agents
 * - PUT    /api/v1/bot/agents/{id}        → update agent
 * - GET    /api/v1/bot/skills             → list skills
 * - PUT    /api/v1/bot/skills/{id}        → update skill
 * - GET    /api/v1/bot/rules              → list notification rules
 * - PUT    /api/v1/bot/rules/{id}         → update rule
 *
 * (Create/Delete não no MVP — todos vêm seedados; admin só liga/desliga e ajusta.)
 */
class BotConfigController extends Controller
{
    public function __construct(
        protected NotificationRoutingService $routing,
        protected NotificationEngine $notificationEngine,
    ) {
    }

    // ── GENERAL ──────────────────────────────────────────────────────
    public function showConfig(): JsonResponse
    {
        $config = BotConfig::singleton()->load('activeProvider');
        return response()->json([
            'data' => [
                'id'                          => $config->id,
                'active_provider'             => $config->activeProvider ? [
                    'id'   => $config->activeProvider->id,
                    'slug' => $config->activeProvider->slug,
                    'name' => $config->activeProvider->name,
                ] : null,
                'default_model'               => $config->default_model,
                'temperature'                 => (float) $config->temperature,
                'frequency_cron'              => $config->frequency_cron,
                'active_hours_start'          => $config->active_hours_start,
                'active_hours_end'            => $config->active_hours_end,
                'default_severity_threshold'  => $config->default_severity_threshold,
                'cooldown_minutes'            => (int) $config->cooldown_minutes,
                'dedupe_window_minutes'       => (int) $config->dedupe_window_minutes,
            ],
        ]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'active_provider_id'         => 'nullable|integer|exists:ai_providers,id',
            'default_model'              => 'nullable|string|max:80',
            'temperature'                => 'nullable|numeric|min:0|max:2',
            'frequency_cron'             => 'nullable|string|max:40',
            'active_hours_start'         => 'nullable|date_format:H:i',
            'active_hours_end'           => 'nullable|date_format:H:i',
            'default_severity_threshold' => 'nullable|in:info,low,medium,high,critical',
            'cooldown_minutes'           => 'nullable|integer|min:0|max:1440',
            'dedupe_window_minutes'      => 'nullable|integer|min:0|max:1440',
        ]);

        $config = BotConfig::singleton();
        $config->update($data);

        return $this->showConfig();
    }

    // ── PROVIDERS ────────────────────────────────────────────────────
    public function providers(): JsonResponse
    {
        $items = AiProviderConfig::query()->orderBy('id')->get()->map(fn ($p) => [
            'id'            => $p->id,
            'slug'          => $p->slug,
            'name'          => $p->name,
            'api_key_env'   => $p->api_key_env,
            'has_key'       => $p->hasKey(),
            'base_url'      => $p->base_url,
            'default_model' => $p->default_model,
            'enabled'       => $p->enabled,
        ]);
        return response()->json(['data' => $items]);
    }

    public function updateProvider(Request $request, int $id): JsonResponse
    {
        $provider = AiProviderConfig::findOrFail($id);
        $data = $request->validate([
            'name'          => 'sometimes|string|max:80',
            'api_key_env'   => 'sometimes|string|max:80',
            'base_url'      => 'sometimes|nullable|string|max:200',
            'default_model' => 'sometimes|string|max:80',
            'enabled'       => 'sometimes|boolean',
        ]);
        $provider->update($data);
        return $this->providers();
    }

    // ── AGENTS ───────────────────────────────────────────────────────
    public function agents(): JsonResponse
    {
        $items = BotAgent::query()->byPriority()->get()->map(fn ($a) => [
            'id'                   => $a->id,
            'slug'                 => $a->slug,
            'name'                 => $a->name,
            'role_description'     => $a->role_description,
            'system_prompt'        => $a->system_prompt,
            'model_override'       => $a->model_override,
            'temperature_override' => $a->temperature_override,
            'active'               => $a->active,
            'priority'             => $a->priority,
            'min_severity'         => $a->min_severity,
            'cooldown_minutes'     => (int) ($a->cooldown_minutes ?? 60),
            'max_per_day'          => (int) ($a->max_per_day ?? 100),
            'trigger_conditions'   => $a->trigger_conditions,
        ]);
        return response()->json(['data' => $items]);
    }

    public function updateAgent(Request $request, int $id): JsonResponse
    {
        $agent = BotAgent::findOrFail($id);
        $data = $request->validate([
            'name'                 => 'sometimes|string|max:120',
            'role_description'     => 'sometimes|nullable|string|max:200',
            'system_prompt'        => 'sometimes|string',
            'model_override'       => 'sometimes|nullable|string|max:80',
            'temperature_override' => 'sometimes|nullable|numeric|min:0|max:2',
            'active'               => 'sometimes|boolean',
            'priority'             => 'sometimes|integer|min:0|max:1000',
            'min_severity'         => 'sometimes|in:info,low,medium,high,critical',
            'cooldown_minutes'     => 'sometimes|integer|min:0|max:1440',
            'max_per_day'          => 'sometimes|integer|min:1|max:10000',
            'trigger_conditions'   => 'sometimes|nullable|array',
        ]);
        $agent->update($data);
        return $this->agents();
    }

    // ── SKILLS ───────────────────────────────────────────────────────
    public function skills(): JsonResponse
    {
        $items = BotSkill::query()->orderBy('id')->get();
        return response()->json(['data' => $items]);
    }

    public function updateSkill(Request $request, int $id): JsonResponse
    {
        $skill = BotSkill::findOrFail($id);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:250',
            'rule_type'   => 'sometimes|in:threshold,sql,event',
            'config'      => 'sometimes|array',
            'severity'    => 'sometimes|in:info,low,medium,high,critical',
            'active'      => 'sometimes|boolean',
        ]);
        $skill->update($data);
        return $this->skills();
    }

    // ── NOTIFICATION RULES ─────────────────────────────────────────
    public function rules(): JsonResponse
    {
        return response()->json(['data' => $this->routing->list()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        try {
            $rule = $this->routing->create($request->all());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => $this->routing->serialize($rule)], 201);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        try {
            $rule = $this->routing->update($id, $request->all());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
        return response()->json(['data' => $this->routing->serialize($rule)]);
    }

    public function destroyRule(int $id): JsonResponse
    {
        $this->routing->delete($id);
        return response()->json(['deleted' => true]);
    }

    public function ruleOptions(): JsonResponse
    {
        return response()->json(['data' => $this->routing->options()]);
    }

    /**
     * POST /api/v1/bot/rules/{id}/test
     * body: { "feed_id"?: <int> }    Se omitido, usa o último feed criado.
     * Retorna preview: severity_match, destinatários, canal, grupo.
     */
    public function testRule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'feed_id' => 'nullable|integer|exists:operational_feed,id',
        ]);

        $feedId = $data['feed_id'] ?? null;
        $feed = $feedId
            ? OperationalFeed::findOrFail($feedId)
            : OperationalFeed::orderByDesc('id')->first();

        if (! $feed) {
            return response()->json(['message' => 'Nenhum feed disponível para testar'], 422);
        }

        return response()->json(['data' => $this->routing->previewWithFeed($id, $feed)]);
    }

    /**
     * POST /api/v1/bot/rules/{id}/dispatch-test
     * Envia EFETIVAMENTE uma mensagem teste pela regra (ignora severity/event filters).
     * O título do feed é prefixado com [TESTE].
     */
    public function dispatchTestRule(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->routing->dispatchTest($id, $this->notificationEngine);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
