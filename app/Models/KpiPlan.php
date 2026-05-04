<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiPlan extends Model
{
    use HasFactory;

    protected $fillable = ['role_slug', 'metric_key', 'daily_plan', 'weight', 'comment'];
}
