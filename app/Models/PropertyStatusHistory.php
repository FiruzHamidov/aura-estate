<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyStatusHistory extends Model
{
    protected $table = 'property_status_history';

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];
}
