<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleFeature extends Model
{
    use HasFactory;

    protected $hidden = [
        'type',
        'pivot',
    ];
    protected $guarded = [];
}
