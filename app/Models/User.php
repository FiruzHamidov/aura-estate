<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name', 'phone', 'email', 'role_id', 'branch_id', 'branch_group_id', 'auth_method', 'status', 'password', 'photo', 'description', 'birthday',
        'telegram_id', 'telegram_username', 'telegram_photo_url', 'telegram_chat_id', 'telegram_linked_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'birthday' => 'date',
        'telegram_linked_at' => 'datetime',
    ];

    public function getStatusAttribute($value): string
    {
        return in_array($value, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)
            ? $value
            : self::STATUS_ACTIVE;
    }


    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchGroup()
    {
        return $this->belongsTo(BranchGroup::class);
    }

    // Быстрая проверка роли в коде:
    public function hasRole(string $slug): bool
    {
        return $this->role && $this->role->slug === $slug;
    }

    public function agentBookings()
    {
        return $this->hasMany(Booking::class, 'agent_id');
    }

    public function clientBookings()
    {
        return $this->hasMany(Booking::class, 'client_id');
    }

    public function createdLeads()
    {
        return $this->hasMany(Lead::class, 'created_by');
    }

    public function responsibleLeads()
    {
        return $this->hasMany(Lead::class, 'responsible_agent_id');
    }

    public function createdDeals()
    {
        return $this->hasMany(Deal::class, 'created_by');
    }

    public function responsibleDeals()
    {
        return $this->hasMany(Deal::class, 'responsible_agent_id');
    }

    public function collaboratingClients()
    {
        return $this->belongsToMany(Client::class, 'client_collaborators')
            ->withPivot(['role', 'granted_by'])
            ->withTimestamps();
    }

    public function crmAuditLogs()
    {
        return $this->hasMany(CrmAuditLog::class, 'actor_id');
    }

    public function soldProperties()
    {
        return $this->belongsToMany(Property::class, 'property_agent_sales', 'agent_id', 'property_id')
            ->withPivot([
                'role',
                'agent_commission_amount',
                'agent_commission_currency',
                'agent_paid_at'
            ]);
    }

    public function reviewsReceived(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }
}
