<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientNeedStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_closed',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function needs()
    {
        return $this->hasMany(ClientNeed::class, 'status_id');
    }
}
