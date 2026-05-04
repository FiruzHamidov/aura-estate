<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiIntegrationStatus extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'status', 'last_checked_at', 'details'];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'details' => 'array',
    ];
}
