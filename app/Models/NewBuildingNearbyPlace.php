<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewBuildingNearbyPlace extends Model
{
    use \App\Models\Concerns\NormalizesVerificationDate;

    protected $fillable = ['new_building_id', 'name', 'type', 'latitude', 'longitude', 'distance_meters', 'distance_method', 'distance_origin_latitude', 'distance_origin_longitude', 'source_url', 'data_verified_at', 'sort_order', 'version'];

    protected $casts = ['latitude' => 'decimal:8', 'longitude' => 'decimal:8', 'distance_origin_latitude' => 'decimal:8', 'distance_origin_longitude' => 'decimal:8', 'distance_meters' => 'integer', 'data_verified_at' => 'datetime', 'version' => 'integer', 'sort_order' => 'integer'];
}
