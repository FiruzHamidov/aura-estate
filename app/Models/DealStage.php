<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealStage extends Model
{
    use HasFactory;

    protected $table = 'crm_deal_stages';

    protected $fillable = [
        'pipeline_id',
        'name',
        'slug',
        'color',
        'sort_order',
        'is_default',
        'is_closed',
        'is_lost',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_closed' => 'boolean',
        'is_lost' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function pipeline()
    {
        return $this->belongsTo(DealPipeline::class, 'pipeline_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'stage_id')
            ->orderBy('board_position')
            ->orderBy('id');
    }

    public function auditLogs()
    {
        return $this->morphMany(CrmAuditLog::class, 'auditable')->latest('id');
    }
}
