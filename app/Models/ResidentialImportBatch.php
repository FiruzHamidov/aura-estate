<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidentialImportBatch extends Model
{
    protected $fillable = ['new_building_id', 'actor_id', 'mode', 'source_name', 'status', 'building_version', 'rows', 'report', 'counts', 'result', 'expires_at', 'applied_at'];

    protected $casts = ['rows' => 'array', 'report' => 'array', 'counts' => 'array', 'result' => 'array', 'building_version' => 'integer', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];
}
