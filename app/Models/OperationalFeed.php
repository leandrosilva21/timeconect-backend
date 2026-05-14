<?php

namespace App\Models;

use App\Enums\FeedEventType;
use App\Enums\FeedSeverity;
use App\Enums\FeedSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalFeed extends Model
{
    use HasFactory;

    protected $table = 'operational_feed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'contract_id',
        'project_id',
        'source',
        'event_type',
        'severity',
        'title',
        'message',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'source'     => FeedSource::class,
        'event_type' => FeedEventType::class,
        'severity'   => FeedSeverity::class,
        'created_at' => 'datetime',
    ];

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

    public function scopeForCustomer(Builder $q, int $customerId): Builder
    {
        return $q->where('customer_id', $customerId);
    }

    public function scopeForContract(Builder $q, int $contractId): Builder
    {
        return $q->where('contract_id', $contractId);
    }

    public function scopeForProject(Builder $q, int $projectId): Builder
    {
        return $q->where('project_id', $projectId);
    }

    public function scopeBySeverity(Builder $q, array|string $severities): Builder
    {
        return $q->whereIn('severity', (array) $severities);
    }

    public function scopeBySource(Builder $q, array|string $sources): Builder
    {
        return $q->whereIn('source', (array) $sources);
    }

    public function scopeRecent(Builder $q): Builder
    {
        return $q->orderByDesc('created_at');
    }
}
