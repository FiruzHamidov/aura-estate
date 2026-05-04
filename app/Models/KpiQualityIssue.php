<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiQualityIssue extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'severity', 'detected_at', 'status', 'details'];

    protected $casts = [
        'detected_at' => 'datetime',
        'details' => 'array',
    ];
}
