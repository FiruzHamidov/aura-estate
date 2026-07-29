<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationStatusEvent extends Model
{
    protected $fillable = ['user_id', 'device_id', 'event', 'meta', 'occurred_at'];

    protected $casts = ['meta' => 'array', 'occurred_at' => 'datetime'];
}
