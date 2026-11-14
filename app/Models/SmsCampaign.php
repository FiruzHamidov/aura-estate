<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsCampaign extends Model
{
    protected $fillable = ['created_by', 'request_id', 'preview_token', 'name', 'message', 'segment', 'transport', 'scheduled_at'];

    protected $hidden = ['preview_token', 'request_id'];

    protected $casts = ['segment' => 'array', 'scheduled_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public static function allowed(?User $actor): bool
    {
        return $actor && $actor->status === User::STATUS_ACTIVE && ! $actor->isDeletedAccount()
            && in_array($actor->role?->slug, ['marketing', 'admin', 'superadmin'], true);
    }

    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if ($actor->usesAssignedGroupScope() || ! $actor->hasPermission('clients.scope.all')
            || ! $actor->hasPermission('leads.scope.all') || ! $actor->hasPermission('users.scope.all')) {
            $query->where('created_by', $actor->id);
        }
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SmsCampaignRecipient::class, 'campaign_id');
    }
}
