<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealPipeline extends Model
{
    use HasFactory;

    protected $table = 'crm_deal_pipelines';

    protected $fillable = [
        'name',
        'slug',
        'branch_id',
        'sort_order',
        'is_default',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function stages()
    {
        return $this->hasMany(DealStage::class, 'pipeline_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function defaultStage()
    {
        return $this->hasOne(DealStage::class, 'pipeline_id')
            ->where('is_default', true)
            ->orderBy('sort_order');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'pipeline_id');
    }

    public function auditLogs()
    {
        return $this->morphMany(CrmAuditLog::class, 'auditable')->latest('id');
    }
}
