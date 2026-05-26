<?php

namespace App\Services\Bot;

use App\Enums\ConversationType;
use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Models\BotNotificationRule;
use App\Models\BotSkill;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class NotificationRoutingService
{
    public const TARGET_TYPES = ['user', 'role', 'group', 'all_admins', 'customer_team', 'all_users'];
    public const CHANNELS     = ['inbox', 'bot_dm', 'group', 'email'];
    public const SEVERITIES   = ['info', 'low', 'medium', 'high', 'critical'];
    public const ROLES        = ['admin', 'coordinator', 'consultant', 'executive', 'partner'];

    public function list(): array
    {
        return BotNotificationRule::query()
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => $this->serialize($r))
            ->all();
    }

    public function find(int $id): BotNotificationRule
    {
        return BotNotificationRule::findOrFail($id);
    }

    public function create(array $data): BotNotificationRule
    {
        $validated = $this->validate($data);
        return BotNotificationRule::create($validated);
    }

    public function update(int $id, array $data): BotNotificationRule
    {
        $rule = $this->find($id);
        $validated = $this->validate($data, partial: true);
        $rule->update($validated);
        return $rule->refresh();
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
    }

    /**
     * Envia EFETIVAMENTE uma mensagem teste pela regra. Usa o último feed
     * disponível como dados (não dispara o feed em si). Útil pro admin
     * verificar que o roteamento + canal funcionam.
     *
     * @return array{rule:array,delivered:int,channel:string,group:?array,recipients_count:int}
     */
    public function dispatchTest(int $ruleId, \App\Services\Bot\NotificationEngine $engine): array
    {
        $rule = $this->find($ruleId);

        $feed = \App\Models\OperationalFeed::orderByDesc('id')->first();
        if (! $feed) {
            throw new \DomainException('Nenhum feed disponível pra usar como base do teste.');
        }

        // Marca como teste no metadata e usa título prefixado
        $originalTitle = $feed->title;
        $feed->title = '[TESTE] ' . $originalTitle;

        // Bypass severity/event_type filters: monta payload direto
        $delivered = $this->deliverDirectly($rule, $feed, $engine);

        // Restaura título pra não persistir alteração (feed continua no DB original)
        $feed->title = $originalTitle;

        $recipientsCount = $this->resolveRecipients($rule, $feed)->count();
        $group = null;
        if ($rule->channel === 'group' && $rule->target_value) {
            $g = \App\Models\Conversation::find((int) $rule->target_value);
            $group = $g ? ['id' => $g->id, 'title' => $g->title] : null;
        }

        return [
            'rule'             => $this->serialize($rule),
            'delivered'        => $delivered,
            'channel'          => $rule->channel,
            'group'            => $group,
            'recipients_count' => $recipientsCount,
        ];
    }

    /**
     * Entrega via canal apropriado, ignorando severity/event filters.
     * Chamado apenas pelo dispatchTest — em produção sempre passa pelo engine.
     */
    private function deliverDirectly(
        \App\Models\BotNotificationRule $rule,
        \App\Models\OperationalFeed $feed,
        \App\Services\Bot\NotificationEngine $engine,
    ): int {
        return $engine->deliverByChannel($rule, $feed);
    }

    /**
     * Retorna o que a regra entregaria para o feed indicado (preview, NÃO envia).
     *
     * @return array{rule:array,severity_match:bool,recipients:array<array{id:int,name:string}>,channel:string,group:?array}
     */
    public function previewWithFeed(int $ruleId, \App\Models\OperationalFeed $feed): array
    {
        $rule = $this->find($ruleId);

        $order = array_flip(self::SEVERITIES);
        $severityMatch = ($order[$feed->severity->value] ?? 0) >= ($order[$rule->severity_min] ?? 0);

        $eventMatch = $rule->event_type === null || $rule->event_type === $feed->event_type->value;

        $recipients = $this->resolveRecipients($rule, $feed)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();

        $group = null;
        if ($rule->channel === 'group') {
            $g = Conversation::find((int) $rule->target_value);
            $group = $g ? ['id' => $g->id, 'title' => $g->title] : null;
        }

        return [
            'rule'           => $this->serialize($rule),
            'severity_match' => $severityMatch && $eventMatch,
            'recipients'     => $recipients,
            'channel'        => $rule->channel,
            'group'          => $group,
        ];
    }

    /**
     * Resolve destinatários — usado pelo NotificationEngine e preview.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function resolveRecipients(BotNotificationRule $rule, ?\App\Models\OperationalFeed $feed = null)
    {
        return match ($rule->target_type) {
            'user' => User::where('id', (int) $rule->target_value)->where('enabled', true)->get(),
            'all_admins' => User::where('enabled', true)->where(function ($q) {
                $q->where('type', 'admin')
                  ->orWhere('type', 'coordinator')
                  ->orWhere('is_executive', true);
            })->get(),
            'role' => User::where('enabled', true)->where('type', $rule->target_value)->get(),
            'group' => $rule->target_value
                ? (Conversation::where('id', (int) $rule->target_value)
                    ->where('type', ConversationType::Group->value)
                    ->first()
                    ?->users
                    ?->where('enabled', true) ?? collect())
                : collect(),
            'customer_team' => $feed && $feed->customer_id
                ? User::where('enabled', true)->where('customer_id', $feed->customer_id)->get()
                : collect(),
            'all_users' => User::where('enabled', true)->limit(500)->get(),
            default => collect(),
        };
    }

    public function options(): array
    {
        $groups = Conversation::where('type', ConversationType::Group->value)
            ->orderBy('title')
            ->get(['id', 'title']);

        $skills = BotSkill::orderBy('name')->get(['slug', 'name']);

        $eventTypes = collect(FeedEventType::cases())
            ->map(fn ($c) => ['value' => $c->value, 'label' => method_exists($c, 'label') ? $c->label() : $c->name])
            ->all();

        return [
            'severities'    => self::SEVERITIES,
            'target_types'  => self::TARGET_TYPES,
            'channels'      => self::CHANNELS,
            'roles'         => self::ROLES,
            'groups'        => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->title ?: "Grupo #{$g->id}"])->all(),
            'skills'        => $skills->map(fn ($s) => ['slug' => $s->slug, 'name' => $s->name])->all(),
            'event_types'   => $eventTypes,
            'trigger_events' => [
                \App\Events\OperationalFeedCreated::class,
            ],
        ];
    }

    public function serialize(BotNotificationRule $r): array
    {
        $groupName = null;
        if ($r->channel === 'group' || $r->target_type === 'group') {
            $g = Conversation::find((int) $r->target_value);
            $groupName = $g?->title;
        }
        $userName = null;
        if ($r->target_type === 'user') {
            $u = User::find((int) $r->target_value);
            $userName = $u?->name;
        }

        return [
            'id'             => $r->id,
            'name'           => $r->name,
            'description'    => $r->description,
            'trigger_event'  => $r->trigger_event,
            'event_type'     => $r->event_type,
            'skill_slug'     => $r->skill_slug,
            'severity_min'   => $r->severity_min,
            'target_type'    => $r->target_type,
            'target_value'   => $r->target_value,
            'target_label'   => $groupName ?? $userName,
            'channel'        => $r->channel,
            'active'         => (bool) $r->active,
            'priority'       => (int) $r->priority,
            'created_at'     => $r->created_at?->toIso8601String(),
            'updated_at'     => $r->updated_at?->toIso8601String(),
        ];
    }

    private function validate(array $data, bool $partial = false): array
    {
        $rules = [
            'name'          => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'description'   => 'nullable|string|max:1000',
            'trigger_event' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'event_type'    => 'nullable|string|max:60',
            'skill_slug'    => 'nullable|string|max:80',
            'severity_min'  => [$partial ? 'sometimes' : 'required', 'in:' . implode(',', self::SEVERITIES)],
            'target_type'   => [$partial ? 'sometimes' : 'required', 'in:' . implode(',', self::TARGET_TYPES)],
            'target_value'  => 'nullable|string|max:120',
            'channel'       => [$partial ? 'sometimes' : 'required', 'in:' . implode(',', self::CHANNELS) . ',teams'],
            'active'        => 'boolean',
            'priority'      => 'integer|min:0|max:9999',
        ];

        $validated = Validator::make($data, $rules)->validate();

        // target_value é obrigatório quando target_type exige (user/role/group)
        if (! $partial) {
            $tt = $validated['target_type'] ?? null;
            if (in_array($tt, ['user', 'role', 'group'], true) && empty($validated['target_value'])) {
                throw new \InvalidArgumentException("target_value é obrigatório para target_type=$tt");
            }
        }

        return $validated;
    }
}
