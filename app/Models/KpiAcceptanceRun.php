<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiAcceptanceRun extends Model
{
    use HasFactory;

    protected $fillable = ['run_type', 'status', 'started_at', 'finished_at', 'details'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'details' => 'array',
    ];
}
