<?php

namespace App\Services\Bot;

use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Models\BotNotificationRule;
use App\Models\Conversation;
use App\Models\OperationalFeed;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * NotificationEngine — avalia bot_notification_rules ativas contra um feed e
 * entrega via canal apropriado (inbox/bot_dm individual, group conversation,
 * ou email/teams placeholder). Não conhece HTTP nem queue; só roteamento.
 */
class NotificationEngine
{
    public function __construct(
        protected BotMinutorService $bot,
        protected AntiNoiseService $antiNoise,
        protected NotificationRoutingService $routing,
    ) {
    }

    public function routeFeedToInbox(OperationalFeed $feed): int
    {
        $dedupeKey = $feed->metadata['dedupe_key'] ?? "feed_{$feed->id}";
        if (! $this->antiNoise->shouldDeliver($dedupeKey, $feed->customer_id)) {
            return 0;
        }

        $rules = BotNotificationRule::query()
            ->active()
            ->forEvent(\App\Events\OperationalFeedCreated::class)
            ->orderBy('priority')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $delivered = 0;
        foreach ($rules as $rule) {
            if (! $this->ruleMatchesFeed($rule, $feed)) {
                continue;
            }

            try {
                $delivered += $this->deliverByChannel($rule, $feed);
            } catch (\Throwable $e) {
                Log::warning('[NotificationEngine] falha ao entregar', [
                    'rule_id' => $rule->id,
                    'feed_id' => $feed->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $delivered;
    }

    protected function ruleMatchesFeed(BotNotificationRule $rule, OperationalFeed $feed): bool
    {
        if (! $this->severityMatches($feed->severity->value, $rule->severity_min)) {
            return false;
        }
        if ($rule->event_type && $rule->event_type !== $feed->event_type->value) {
            return false;
        }
        if ($rule->skill_slug) {
            $feedSkill = $feed->metadata['skill_slug'] ?? null;
            if ($feedSkill !== $rule->skill_slug) {
                return false;
            }
        }
        return true;
    }

    public function deliverByChannel(BotNotificationRule $rule, OperationalFeed $feed): int
    {
        $metadata = [
            'feed_id'    => $feed->id,
            'severity'   => $feed->severity->value,
            'event_type' => $feed->event_type->value,
            'source'     => $feed->source->value,
            'customer_id'=> $feed->customer_id,
            'contract_id'=> $feed->contract_id,
            'project_id' => $feed->project_id,
            'provider'   => $feed->metadata['provider'] ?? null,
            'rule_id'    => $rule->id,
        ];

        $type = $feed->source->value === 'ai' ? MessageType::AiInsight : MessageType::Alert;

        return match ($rule->channel) {
            'inbox', 'bot_dm' => $this->deliverToIndividualInboxes($rule, $feed, $type, $metadata),
            'group'           => $this->deliverToGroup($rule, $feed, $type, $metadata),
            'email'           => $this->deliverEmail($rule, $feed, $metadata),
            'teams'           => $this->deliverEmail($rule, $feed, $metadata), // legacy, placeholder
            default           => 0,
        };
    }

    protected function deliverToIndividualInboxes(
        BotNotificationRule $rule, OperationalFeed $feed, MessageType $type, array $metadata,
    ): int {
        $recipients = $this->routing->resolveRecipients($rule, $feed);
        $count = 0;
        foreach ($recipients as $user) {
            if ($type === MessageType::AiInsight) {
                $this->bot->deliverAiInsight($user, $feed->title, $feed->message, $metadata);
            } else {
                $this->bot->deliverAlert($user, $feed->title, $feed->message, $metadata);
            }
            $count++;
        }
        return $count;
    }

    protected function deliverToGroup(
        BotNotificationRule $rule, OperationalFeed $feed, MessageType $type, array $metadata,
    ): int {
        if (! $rule->target_value) {
            return 0;
        }
        $conv = Conversation::where('id', (int) $rule->target_value)
            ->where('type', ConversationType::Group->value)
            ->first();
        if (! $conv) {
            return 0;
        }
        $this->bot->deliverToConversation($conv, $type, $feed->title, $feed->message, $metadata);
        return 1;
    }

    protected function deliverEmail(BotNotificationRule $rule, OperationalFeed $feed, array $metadata): int
    {
        // Placeholder — não implementado nesta fatia
        Log::info('[NotificationEngine] email channel pendente de implementação', [
            'rule_id' => $rule->id, 'feed_id' => $feed->id,
        ]);
        return 0;
    }

    protected function severityMatches(string $feedSeverity, string $ruleMin): bool
    {
        $order = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        return ($order[$feedSeverity] ?? 0) >= ($order[$ruleMin] ?? 0);
    }
}
