<?php

namespace App\Services\Bot;

use App\Models\BotNotificationRule;
use App\Models\OperationalFeed;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * NotificationEngine — decide PARA QUEM entregar uma notificação a partir
 * das `bot_notification_rules` ativas. Não conhece HTTP nem queue; só roteamento.
 */
class NotificationEngine
{
    public function __construct(
        protected BotMinutorService $bot,
        protected AntiNoiseService $antiNoise,
    ) {
    }

    public function routeFeedToInbox(OperationalFeed $feed): int
    {
        // Anti-ruído: stub hoje, motor real quando ativado
        $dedupeKey = $feed->metadata['dedupe_key'] ?? "feed_{$feed->id}";
        if (! $this->antiNoise->shouldDeliver($dedupeKey, $feed->customer_id)) {
            return 0;
        }

        $rules = BotNotificationRule::query()
            ->active()
            ->forEvent(\App\Events\OperationalFeedCreated::class)
            ->where('channel', 'inbox')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $delivered = 0;
        foreach ($rules as $rule) {
            if (! $this->severityMatches($feed->severity->value, $rule->severity_min)) {
                continue;
            }

            $recipients = $this->resolveRecipients($rule, $feed);

            foreach ($recipients as $user) {
                try {
                    if ($feed->source->value === 'ai') {
                        $this->bot->deliverAiInsight(
                            user: $user,
                            title: $feed->title,
                            body: $feed->message,
                            metadata: [
                                'feed_id'    => $feed->id,
                                'severity'   => $feed->severity->value,
                                'event_type' => $feed->event_type->value,
                                'source'     => $feed->source->value,
                                'customer_id'=> $feed->customer_id,
                                'provider'   => $feed->metadata['provider'] ?? null,
                            ],
                        );
                    } else {
                        $this->bot->deliverAlert(
                            user: $user,
                            title: $feed->title,
                            body: $feed->message,
                            metadata: [
                                'feed_id'    => $feed->id,
                                'severity'   => $feed->severity->value,
                                'event_type' => $feed->event_type->value,
                                'source'     => $feed->source->value,
                                'customer_id'=> $feed->customer_id,
                            ],
                        );
                    }
                    $delivered++;
                } catch (\Throwable $e) {
                    Log::warning('[NotificationEngine] falha ao entregar', [
                        'user_id' => $user->id,
                        'feed_id' => $feed->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        return $delivered;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function resolveRecipients(BotNotificationRule $rule, OperationalFeed $feed)
    {
        return match ($rule->target_type) {
            'user' => User::where('id', (int) $rule->target_value)->get(),
            'all_admins' => User::where(function ($q) {
                $q->where('type', 'admin')
                  ->orWhere('type', 'coordinator')
                  ->orWhere('is_executive', true);
            })->get(),
            'role' => User::where('type', $rule->target_value)->get(),
            'customer_team' => $feed->customer_id
                ? User::where('customer_id', $feed->customer_id)->get()
                : collect(),
            default => collect(),
        };
    }

    protected function severityMatches(string $feedSeverity, string $ruleMin): bool
    {
        $order = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        return ($order[$feedSeverity] ?? 0) >= ($order[$ruleMin] ?? 0);
    }
}
