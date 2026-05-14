<?php

namespace App\Models;

use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'type', 'title', 'customer_id', 'contract_id', 'project_id',
        'created_by', 'last_message_at',
    ];

    protected $casts = [
        'type'            => ConversationType::class,
        'last_message_at' => 'datetime',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('role', 'joined_at', 'last_read_at', 'muted');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->whereHas('participants', fn ($p) => $p->where('user_id', $userId));
    }

    public function scopeRecent(Builder $q): Builder
    {
        return $q->orderByDesc('last_message_at')->orderByDesc('id');
    }
}
