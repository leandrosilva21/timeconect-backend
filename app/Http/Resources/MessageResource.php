<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'type'            => [
                'value' => $this->type?->value,
                'label' => $this->type?->label(),
            ],
            'sender'          => $this->whenLoaded('sender', fn () => $this->sender ? [
                'id'                 => $this->sender->id,
                'name'               => $this->sender->name,
                'profile_photo_url'  => $this->sender->profile_photo_url ?? null,
            ] : null),
            'body'            => $this->body,
            'metadata'        => $this->metadata,
            'reply_to_id'     => $this->reply_to_id,
            'status'          => $this->status?->value ?? 'unread',
            'snoozed_until'   => $this->snoozed_until?->toIso8601String(),
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
