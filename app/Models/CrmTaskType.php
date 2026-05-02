<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrmTaskType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'group',
        'is_kpi',
        'is_active',
    ];

    protected $casts = [
        'is_kpi' => 'boolean',
        'is_active' => 'boolean',
    ];
}
