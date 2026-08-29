<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewBuildingVideo extends Model
{
    use \App\Models\Concerns\NormalizesVerificationDate;

    protected $fillable = ['new_building_id', 'title', 'provider', 'provider_id', 'url', 'data_verified_at', 'sort_order', 'version'];

    protected $casts = ['data_verified_at' => 'datetime', 'version' => 'integer', 'sort_order' => 'integer'];
}
