<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiEarlyRiskAlert extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'alert_date', 'status', 'message', 'meta'];

    protected $casts = [
        'alert_date' => 'date',
        'meta' => 'array',
    ];
}
