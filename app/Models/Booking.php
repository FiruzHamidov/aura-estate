<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use \App\Models\Concerns\ProjectsRopRelations;

    use HasFactory;

    public const STATUSES = ['pending', 'confirmed', 'completed', 'not_held', 'cancelled'];
    public const OUTCOMES = ['interested', 'thinking', 'not_suitable', 'undecided'];
    public const REASONS = ['price', 'location', 'condition', 'layout', 'client_no_show', 'owner_unavailable', 'property_unavailable', 'other'];

    protected $casts = [
        'result_recorded_at' => 'datetime',
        'result_version' => 'integer',
    ];

    protected $fillable = [
        'branch_group_id',
        'property_id',
        'agent_id',
        'co_agent_id',
        'client_id',
        'crm_client_id',
        'start_time',
        'end_time',
        'note',
        'status',
        'client_name',
        'client_phone'
    ];

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'created_by')) {
                // Do not accept an author supplied by the client. Imports without an actor use the agent.
                $booking->created_by = auth()->id() ?? $booking->agent_id;
            }
        });
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'crm_client_id');
    }

    public function coAgent()
    {
        return $this->belongsTo(User::class, 'co_agent_id');
    }

    public function followUpTask()
    {
        return $this->belongsTo(CrmTask::class, 'follow_up_task_id');
    }

    public function legacyClientUser()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
