<?php

namespace App\Events;

use App\Models\OperationalFeed;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperationalFeedCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly OperationalFeed $feed)
    {
    }
}
