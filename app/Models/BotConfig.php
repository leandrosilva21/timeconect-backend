<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotConfig extends Model
{
    protected $fillable = [
        'active_provider_id', 'default_model', 'temperature',
        'frequency_cron', 'active_hours_start', 'active_hours_end',
        'default_severity_threshold',
        'cooldown_minutes', 'dedupe_window_minutes',
    ];

    protected $casts = [
        'temperature' => 'decimal:2',
    ];

    public function activeProvider(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfig::class, 'active_provider_id');
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'default_model'              => 'claude-sonnet-4-6',
            'temperature'                => 0.30,
            'frequency_cron'             => '0 6 * * 1',
            'default_severity_threshold' => 'high',
        ]);
    }
}
