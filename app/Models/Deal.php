<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'crm_deals';

    protected $fillable = [
        'title',
        'client_id',
        'lead_id',
        'branch_id',
        'created_by',
        'responsible_agent_id',
        'pipeline_id',
        'stage_id',
        'primary_property_id',
        'amount',
        'currency',
        'probability',
        'expected_company_income',
        'expected_company_income_currency',
        'expected_agent_commission',
        'expected_agent_commission_currency',
        'actual_company_income',
        'actual_company_income_currency',
        'deadline_at',
        'closed_at',
        'lost_reason',
        'source',
        'board_position',
        'meta',
        'note',
        'tags',
        'last_contact_result',
        'next_activity_at',
        'source_property_status',
        'updated_by',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
        'tags' => 'array',
        'next_activity_at' => 'datetime',
    ];

    protected $appends = [
        'is_closed',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleAgent()
    {
        return $this->belongsTo(User::class, 'responsible_agent_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function pipeline()
    {
        return $this->belongsTo(DealPipeline::class, 'pipeline_id');
    }

    public function stage()
    {
        return $this->belongsTo(DealStage::class, 'stage_id');
    }

    public function primaryProperty()
    {
        return $this->belongsTo(Property::class, 'primary_property_id');
    }

    public function auditLogs()
    {
        return $this->morphMany(CrmAuditLog::class, 'auditable')->latest('id');
    }

    public function activities()
    {
        return $this->morphMany(CrmAuditLog::class, 'auditable')->latest('id');
    }

    public function getIsClosedAttribute(): bool
    {
        return ! is_null($this->closed_at) || (bool) ($this->stage?->is_closed ?? false);
    }
}
