<?php

namespace App\Listeners;

use App\Events\OperationalFeedCreated;
use App\Services\Bot\NotificationEngine;

class RouteFeedToInbox
{
    public function __construct(protected NotificationEngine $engine)
    {
    }

    public function handle(OperationalFeedCreated $event): void
    {
        $this->engine->routeFeedToInbox($event->feed);
    }
}
