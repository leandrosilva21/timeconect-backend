<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalFeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),
            'contract' => $this->whenLoaded('contract', fn () => [
                'id' => $this->contract?->id,
            ]),
            'project'  => $this->whenLoaded('project', fn () => [
                'id'   => $this->project?->id,
                'name' => $this->project?->name,
            ]),
            'source'     => [
                'value' => $this->source?->value,
                'label' => $this->source?->label(),
            ],
            'event_type' => [
                'value' => $this->event_type?->value,
                'label' => $this->event_type?->label(),
            ],
            'severity' => [
                'value' => $this->severity?->value,
                'label' => $this->severity?->label(),
                'color' => $this->severity?->color(),
            ],
            'title'      => $this->title,
            'message'    => $this->message,
            'metadata'   => $this->metadata,
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator?->id,
                'name' => $this->creator?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
