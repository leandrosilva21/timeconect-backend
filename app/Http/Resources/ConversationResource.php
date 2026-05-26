<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'type'            => $this->type?->value,
            'title'           => $this->title,
            'customer'        => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
