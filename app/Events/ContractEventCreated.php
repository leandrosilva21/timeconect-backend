<?php

namespace App\Events;

use App\Models\ContractEvent;

class ContractEventCreated
{
    public function __construct(public readonly ContractEvent $contractEvent) {}
}
