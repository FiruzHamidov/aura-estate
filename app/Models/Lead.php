<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_LOST = 'lost';

    public const DEFAULT_FIRST_CONTACT_SLA_MINUTES = 15;

    protected $fillable = [
        'full_name',
        'phone',
        'phone_normalized',
        'email',
        'note',
        'source',
        'branch_id',
        'created_by',
        'responsible_agent_id',
        'client_id',
        'converted_client_id',
        'converted_deal_id',
        'client_need_id',
        'status',
        'budget',
        'currency',
        'first_contact_due_at',
        'first_contacted_at',
        'last_activity_at',
        'converted_at',
        'closed_at',
        'lost_reason',
        'meta',
        'tags',
        'last_contact_result',
        'next_follow_up_at',
        'next_activity_at',
        'updated_by',
    ];

    protected $casts = [
        'meta' => 'array',
        'tags' => 'array',
        'budget' => 'decimal:2',
        'first_contact_due_at' => 'datetime',
        'first_contacted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'converted_at' => 'datetime',
        'closed_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'next_activity_at' => 'datetime',
    ];

    protected $appends = [
        'is_first_contact_overdue',
        'is_closed',
        'need_id',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_ASSIGNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_QUALIFIED,
            self::STATUS_CONVERTED,
            self::STATUS_LOST,
        ];
    }

    public static function closedStatuses(): array
    {
        return [
            self::STATUS_CONVERTED,
            self::STATUS_LOST,
        ];
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function clientNeed()
    {
        return $this->belongsTo(ClientNeed::class);
    }

    public function need()
    {
        return $this->clientNeed();
    }

    public function deals()
    {
        return $this->hasMany(Deal::class)->latest('id');
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
        return in_array($this->status, self::closedStatuses(), true);
    }

    public function getIsFirstContactOverdueAttribute(): bool
    {
        if ($this->is_closed || $this->first_contacted_at || ! $this->first_contact_due_at) {
            return false;
        }

        return $this->first_contact_due_at->isPast();
    }

    public function getNeedIdAttribute(): ?int
    {
        return $this->client_need_id ? (int) $this->client_need_id : null;
    }
}
