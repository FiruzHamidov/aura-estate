<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class KpiPlanningDraft extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'revision' => 'integer', 'step' => 'integer'];
}
