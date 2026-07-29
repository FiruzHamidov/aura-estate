<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationViewPreference extends Model
{
    protected $fillable = ['viewer_user_id', 'mode', 'filters'];

    protected $casts = ['filters' => 'array'];
}
