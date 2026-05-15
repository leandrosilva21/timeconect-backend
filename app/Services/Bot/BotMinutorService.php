<?php

namespace App\Services\Bot;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Inbox\InboxService;

/**
 * BotMinutorService — entrega centralizada de notificações do BOT para usuários.
 * Cada usuário tem uma conversation `bot`; o BOT entrega mensagens nela.
 */
class BotMinutorService
{
    public function __construct(protected InboxService $inbox)
    {
    }

    public function deliverAlert(User $user, string $title, string $body, array $metadata = []): Message
    {
        $conv = $this->inbox->ensureBotConversation($user);

        $msgBody = $title ? "**{$title}**\n\n{$body}" : $body;

        return $this->inbox->persist($conv, [
            'sender_user_id' => null,
            'type'           => MessageType::Alert,
            'body'           => $msgBody,
            'metadata'       => array_merge(['title' => $title], $metadata),
        ]);
    }

    public function deliverAiInsight(User $user, string $title, string $body, array $metadata = []): Message
    {
        $conv = $this->inbox->ensureBotConversation($user);

        return $this->inbox->persist($conv, [
            'sender_user_id' => null,
            'type'           => MessageType::AiInsight,
            'body'           => $body,
            'metadata'       => array_merge(['title' => $title], $metadata),
        ]);
    }

    public function deliverBotMessage(User $user, string $body, array $metadata = []): Message
    {
        $conv = $this->inbox->ensureBotConversation($user);

        return $this->inbox->persist($conv, [
            'sender_user_id' => null,
            'type'           => MessageType::Bot,
            'body'           => $body,
            'metadata'       => $metadata,
        ]);
    }

    /**
     * Entrega mensagem do BOT em uma Conversation específica (grupo).
     * Usado pelo NotificationEngine quando o canal da regra é "group".
     */
    public function deliverToConversation(
        Conversation $conv,
        MessageType $type,
        string $title,
        string $body,
        array $metadata = [],
    ): Message {
        $msgBody = $title ? "**{$title}**\n\n{$body}" : $body;

        return $this->inbox->persist($conv, [
            'sender_user_id' => null,
            'type'           => $type,
            'body'           => $msgBody,
            'metadata'       => array_merge(['title' => $title], $metadata),
        ]);
    }
}
