<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillLevel extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'weight'];

    protected $casts = [
        'weight' => 'integer',
    ];
}
