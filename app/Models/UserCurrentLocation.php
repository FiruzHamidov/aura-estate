<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCurrentLocation extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'location_point_id', 'latitude', 'longitude',
        'accuracy_m', 'quality', 'captured_at', 'received_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
