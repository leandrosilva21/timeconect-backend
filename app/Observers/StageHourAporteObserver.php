<?php

namespace App\Observers;

use App\Models\StageActivityEvent;
use App\Models\StageHourAporte;

class StageHourAporteObserver
{
    public function created(StageHourAporte $aporte): void
    {
        StageActivityEvent::create([
            'stage_id'      => $aporte->stage_id,
            'actor_user_id' => $aporte->user_id,
            'type'          => StageActivityEvent::TYPE_APORTE_CREATED,
            'payload'       => [
                'aporte_id' => $aporte->id,
                'hours'     => (float) $aporte->hours,
                'reason'    => $aporte->reason,
            ],
        ]);
    }
}
